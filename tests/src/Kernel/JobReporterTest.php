<?php

declare(strict_types=1);

namespace Drupal\Tests\tracker_monitoring\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\tracker_monitoring\JobReporter;
use Drupal\tracker_monitoring\Key;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;

/**
 * Tests what a job's start and end look like to Tracker, and what is tried.
 */
#[Group('tracker_monitoring')]
#[RunTestsInSeparateProcesses]
final class JobReporterTest extends KernelTestBase {

  private const KEY = '12.0123456789abcdef';

  /**
   * The requests the mocked Tracker got.
   *
   * @var array<int, array{request: \Psr\Http\Message\RequestInterface}>
   */
  private array $sent = [];

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'tracker_monitoring'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['tracker_monitoring']);
    $this->config('tracker_monitoring.settings')->set('endpoint', 'https://tracker.example.com/')->save();
    new Settings(['tracker_monitoring_key' => self::KEY] + Settings::getAll());
  }

  /**
   * A job cannot be reported without Tracker's address and a key.
   */
  public function testJobCannotBeReportedWithoutAddressOrKey(): void {
    $reporter = $this->reporter([]);
    $this->assertNull($reporter->problem('queue-run'));

    new Settings(['tracker_monitoring_key' => ''] + Settings::getAll());
    $this->assertStringContainsString('key is not set', (string) $reporter->problem('queue-run'));

    new Settings(['tracker_monitoring_key' => self::KEY] + Settings::getAll());
    $this->config('tracker_monitoring.settings')->set('endpoint', NULL)->save();
    $this->assertStringContainsString('address is not set', (string) $reporter->problem('queue-run'));
  }

  /**
   * A name Tracker would refuse is said before anything is sent.
   */
  public function testNameTrackerWouldRefuseIsSaidBeforeSending(): void {
    $reporter = $this->reporter([]);

    foreach (['Queue Run', '-queue', '', str_repeat('a', 101), 'queue/run'] as $name) {
      $this->assertStringContainsString('is not a job name', (string) $reporter->problem($name), $name);
    }
    $this->assertNull($reporter->problem(str_repeat('a', 100)));
    $this->assertNull($reporter->problem('nightly_import-2'));
    $this->assertSame([], $this->sent);
  }

  /**
   * The start and the end are signed the way Tracker checks them.
   */
  public function testTheStartAndTheEndAreSignedTheWayTrackerChecks(): void {
    $ok = new Response(200, [], '{"ok":true}');
    $reporter = $this->reporter([$ok, $ok]);

    $this->assertNull($reporter->start('queue-run'));
    $this->assertNull($reporter->finish('queue-run', 0, 12, 'all fine'));

    $this->assertCount(2, $this->sent);
    foreach ($this->sent as $entry) {
      $request = $entry['request'];
      $this->assertSame('https://tracker.example.com/hooks/job', (string) $request->getUri());
      $this->assertSame('12', $request->getHeaderLine('X-Tracker-Project'));
      $body = (string) $request->getBody();
      $this->assertSame(
        hash_hmac('sha256', $request->getHeaderLine('X-Tracker-Timestamp') . '.' . $body, self::KEY),
        $request->getHeaderLine('X-Tracker-Signature'),
      );
    }
    $this->assertSame(['job' => 'queue-run', 'event' => 'start'], $this->payload(0));
    $this->assertSame(['job' => 'queue-run', 'event' => 'finish', 'exit' => 0, 'seconds' => 12], $this->payload(1));
  }

  /**
   * Only a failed run sends its output, and only the end of it.
   */
  public function testOnlyFailedRunSendsItsOutputAndOnlyTheEndOfIt(): void {
    $ok = new Response(200, [], '{"ok":true}');
    $reporter = $this->reporter([$ok, $ok]);

    $reporter->finish('queue-run', 0, 1, 'private but harmless');
    $reporter->finish('queue-run', 1, 1, str_repeat('x', 30000) . 'the end');

    $this->assertArrayNotHasKey('output', $this->payload(0));
    $output = $this->payload(1)['output'];
    $this->assertLessThanOrEqual(JobReporter::MAX_OUTPUT, strlen($output));
    $this->assertStringEndsWith('the end', $output);
  }

  /**
   * Output that is not valid UTF-8 does not stop the report.
   */
  public function testOutputThatIsNotValidUtf8DoesNotStopTheReport(): void {
    $reporter = $this->reporter([new Response(200, [], '{"ok":true}')]);

    $this->assertNull($reporter->finish('queue-run', 1, 1, "broken \xff\xfe bytes"));
    $this->assertStringContainsString('broken', $this->payload(0)['output']);
  }

  /**
   * The time of sending is signed, not the time the process started.
   *
   * A job can run for hours; Tracker refuses a timestamp over five minutes off.
   */
  public function testTheTimeOfSendingIsSignedNotTheTimeOfTheRequest(): void {
    $time = new class() implements TimeInterface {

      /**
       * {@inheritdoc}
       */
      public function getRequestTime(): int {
        return 1000;
      }

      /**
       * {@inheritdoc}
       */
      public function getRequestMicroTime(): float {
        return 1000.0;
      }

      /**
       * {@inheritdoc}
       */
      public function getCurrentTime(): int {
        return 5000;
      }

      /**
       * {@inheritdoc}
       */
      public function getCurrentMicroTime(): float {
        return 5000.0;
      }

    };
    $reporter = $this->reporter([new Response(200, [], '{"ok":true}')], $time);

    $reporter->start('queue-run');

    $this->assertSame('5000', $this->sent[0]['request']->getHeaderLine('X-Tracker-Timestamp'));
  }

  /**
   * A Tracker that is busy is asked three times, then the reason is given.
   */
  public function testBusyTrackerIsAskedThreeTimes(): void {
    $busy = new Response(503, [], '{"error":"Try later."}');
    $reporter = $this->reporter([$busy, $busy, new Response(200, [], '{"ok":true}')]);
    $this->assertNull($reporter->start('queue-run'));
    $this->assertCount(3, $this->sent);

    $reporter = $this->reporter([$busy, $busy, $busy]);
    $message = $reporter->start('queue-run');
    $this->assertCount(3, $this->sent);
    $this->assertStringContainsString('Tracker answered 503 (Try later.)', (string) $message);
  }

  /**
   * A refusal is not asked again: a wrong key stays wrong.
   */
  public function testRefusalIsNotAskedAgain(): void {
    $reporter = $this->reporter([new Response(401, [], '{"error":"Bad signature."}')]);

    $message = $reporter->start('queue-run');

    $this->assertCount(1, $this->sent);
    $this->assertStringContainsString('Tracker answered 401 (Bad signature.)', (string) $message);
  }

  /**
   * A Tracker that cannot be reached is said, not thrown.
   */
  public function testTrackerThatCannotBeReachedIsSaidNotThrown(): void {
    $down = new ConnectException('Connection refused', new Request('POST', 'https://tracker.example.com/hooks/job'));
    $reporter = $this->reporter([$down, $down, $down]);

    $message = $reporter->finish('queue-run', 0, 1, '');

    $this->assertCount(3, $this->sent);
    $this->assertStringContainsString('Tracker could not be reached', (string) $message);
  }

  /**
   * Gets the JSON of one of the requests Tracker got.
   *
   * @return array<string, mixed>
   *   The report.
   */
  private function payload(int $index): array {
    return json_decode((string) $this->sent[$index]['request']->getBody(), TRUE, 8, JSON_THROW_ON_ERROR);
  }

  /**
   * Makes a reporter whose Tracker answers with the given responses.
   *
   * @param array<int, \Psr\Http\Message\ResponseInterface|\Throwable> $answers
   *   What Tracker answers, in order.
   * @param \Drupal\Component\Datetime\TimeInterface|null $time
   *   The clock, when the test needs its own.
   */
  private function reporter(array $answers, ?TimeInterface $time = NULL): JobReporter {
    $this->sent = [];
    $stack = HandlerStack::create(new MockHandler($answers));
    $stack->push(Middleware::history($this->sent));
    return new JobReporter(
      $this->container->get('config.factory'),
      new Client(['handler' => $stack]),
      $time ?? $this->container->get('datetime.time'),
      new NullLogger(),
      0,
    );
  }

  /**
   * Keeps the key helper in use for the signature check above.
   */
  public function testTheKeyIsTheOneInTheSettings(): void {
    $this->assertSame(self::KEY, Key::get());
  }

}
