<?php

declare(strict_types=1);

namespace Drupal\Tests\tracker_monitoring\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\tracker_monitoring\PackageReader;
use Drupal\tracker_monitoring\Report;
use Drupal\tracker_monitoring\ReportScheduler;
use Drupal\tracker_monitoring\ReportSender;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;

/**
 * Tests which reports cron sends, and when.
 */
#[Group('tracker_monitoring')]
#[RunTestsInSeparateProcesses]
final class ReportSchedulerTest extends KernelTestBase {

  /**
   * The time of the test, in seconds; the tests move it.
   */
  private int $now = 1_800_000_000;

  /**
   * The requests the mocked Tracker got.
   *
   * @var array<int, array<string, mixed>>
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
    $this->config('tracker_monitoring.settings')->set('endpoint', 'https://tracker.example.com')->save();
    new Settings(['tracker_monitoring_key' => '12.0123456789abcdef'] + Settings::getAll());
  }

  /**
   * Nothing is sent when no report is switched on.
   */
  public function testNothingIsSentWhenNothingIsOn(): void {
    $scheduler = $this->scheduler([]);

    $this->assertSame([], $scheduler->run());
    $this->assertSame([], $this->sent);
  }

  /**
   * A report that was sent waits a day; one that failed waits an hour.
   */
  public function testSentWaitsOneDayAndFailedOneHour(): void {
    $this->switchOn(Report::Packages);
    $scheduler = $this->scheduler([
      new Response(200, [], '{"ok":true}'),
      new Response(503, [], '{"error":"down"}'),
      new Response(200, [], '{"ok":true}'),
    ]);

    $this->assertCount(1, $scheduler->run(), 'due the first time');
    $this->assertSame([], $scheduler->run(), 'not again at once');
    $this->now += 86399;
    $this->assertSame([], $scheduler->run(), 'not before a day has passed');

    $this->now += 1;
    $results = $scheduler->run();
    $this->assertCount(1, $results);
    $this->assertFalse($results[0]->success, 'Tracker answered 503');

    $this->now += ReportScheduler::RETRY_AFTER - 1;
    $this->assertSame([], $scheduler->run(), 'a failure is not retried before an hour');
    $this->now += 1;
    $this->assertCount(1, $scheduler->run());
    $this->assertCount(3, $this->sent);
  }

  /**
   * Each report keeps its own time.
   */
  public function testReportsAreScheduledOnTheirOwn(): void {
    $this->switchOn(Report::Packages);
    $scheduler = $this->scheduler([new Response(200, [], '{"ok":true}'), new Response(200, [], '{"ok":true}')]);

    $this->assertCount(1, $scheduler->run());

    $this->switchOn(Report::Status);
    $results = $scheduler->run();
    $this->assertCount(1, $results);
    $this->assertSame(Report::Status, $results[0]->report);
  }

  /**
   * The log rows are due every hour; without dblog the report fails and waits.
   */
  public function testLogsAreDueEveryHourAndFailureWaitsAnHour(): void {
    $this->assertSame(3600, Report::Logs->interval());
    $this->switchOn(Report::Logs);
    $scheduler = $this->scheduler([]);

    $results = $scheduler->run();

    $this->assertCount(1, $results);
    $this->assertFalse($results[0]->success);
    $this->assertStringContainsString('dblog', $results[0]->message);
    $this->now += ReportScheduler::RETRY_AFTER - 1;
    $this->assertSame([], $scheduler->run());
    $this->now += 1;
    $this->assertCount(1, $scheduler->run());
  }

  /**
   * Drupal's cron runs the scheduler.
   */
  public function testCronRunsTheScheduler(): void {
    $this->switchOn(Report::Packages);
    // Without a key nothing leaves the site, and the failed report is booked
    // for a retry, which shows that the hook ran.
    new Settings(['tracker_monitoring_key' => ''] + Settings::getAll());
    $state = $this->container->get('state');
    $this->assertNull($state->get('tracker_monitoring.next_send.packages'));

    $this->container->get('module_handler')->invokeAll('cron');

    $this->assertGreaterThan(0, $state->get('tracker_monitoring.next_send.packages'));
  }

  /**
   * Makes a scheduler whose Tracker answers with the given responses.
   *
   * @param array<int, \Psr\Http\Message\ResponseInterface> $answers
   *   What Tracker answers, in order.
   */
  private function scheduler(array $answers): ReportScheduler {
    $stack = HandlerStack::create(new MockHandler($answers));
    $stack->push(Middleware::history($this->sent));
    $time = new class($this) implements TimeInterface {

      public function __construct(private readonly ReportSchedulerTest $test) {}

      /**
       * {@inheritdoc}
       */
      public function getRequestTime(): int {
        return $this->test->now();
      }

      /**
       * {@inheritdoc}
       */
      public function getRequestMicroTime(): float {
        return (float) $this->test->now();
      }

      /**
       * {@inheritdoc}
       */
      public function getCurrentTime(): int {
        return $this->test->now();
      }

      /**
       * {@inheritdoc}
       */
      public function getCurrentMicroTime(): float {
        return (float) $this->test->now();
      }

    };
    $sender = new ReportSender(
      $this->container->get('config.factory'),
      new Client(['handler' => $stack]),
      $time,
      new PackageReader(dirname(__DIR__, 2) . '/fixtures/site'),
      $this->container->get('tracker_monitoring.status_collector'),
      $this->container->get('tracker_monitoring.log_collector'),
      new NullLogger(),
    );
    return new ReportScheduler($sender, $this->container->get('state'), $time);
  }

  /**
   * Gets the time of the test.
   */
  public function now(): int {
    return $this->now;
  }

  /**
   * Switches a report on in the settings.
   */
  private function switchOn(Report $report): void {
    $this->config('tracker_monitoring.settings')->set($report->settingKey(), TRUE)->save();
  }

}
