<?php

declare(strict_types=1);

namespace Drupal\Tests\tracker_monitoring\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\tracker_monitoring\Command\RunCommand;
use Drupal\tracker_monitoring\JobReporter;
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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tests tracker-monitoring:run.
 *
 * The command runs as it would alone, and Tracker hears of it.
 */
#[Group('tracker_monitoring')]
#[RunTestsInSeparateProcesses]
final class RunCommandTest extends KernelTestBase {

  private const KEY = '12.0123456789abcdef';

  /**
   * The requests the mocked Tracker got.
   *
   * @var array<int, array{request: \Psr\Http\Message\RequestInterface}>
   */
  private array $sent = [];

  /**
   * What the command wrote to the console.
   */
  private BufferedOutput $output;

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
   * A run that succeeds is reported at its start and its end, with no output.
   */
  public function testSuccessfulRunIsReportedAtItsStartAndItsEnd(): void {
    $code = $this->execute(['sh', '-c', 'echo hello'], $this->answers(2));

    $this->assertSame(0, $code);
    $this->assertStringContainsString('hello', $this->output->fetch());
    $this->assertCount(2, $this->sent);
    $this->assertSame(['job' => 'queue-run', 'event' => 'start'], $this->payload(0));
    $finish = $this->payload(1);
    $this->assertSame('finish', $finish['event']);
    $this->assertSame(0, $finish['exit']);
    $this->assertArrayNotHasKey('output', $finish);
  }

  /**
   * A failed run keeps its exit code and sends the end of what it printed.
   */
  public function testFailedRunKeepsItsExitCodeAndSendsItsOutput(): void {
    $code = $this->execute(['sh', '-c', 'echo first; echo second >&2; exit 3'], $this->answers(2));

    $this->assertSame(3, $code);
    $finish = $this->payload(1);
    $this->assertSame(3, $finish['exit']);
    $this->assertStringContainsString('first', $finish['output']);
    $this->assertStringContainsString('second', $finish['output']);
  }

  /**
   * What a command prints is not read as console formatting.
   */
  public function testWhatTheCommandPrintsIsPassedOnAsItIs(): void {
    $this->execute(['sh', '-c', 'echo "<error>not a tag</error> <info>"'], $this->answers(2));

    $this->assertStringContainsString('<error>not a tag</error> <info>', $this->output->fetch());
  }

  /**
   * A process that a signal ended is reported as the shell would say it.
   */
  public function testProcessEndedBySignalIsReportedAsTheShellWouldSayIt(): void {
    $code = $this->execute(['sh', '-c', 'kill -TERM $$'], $this->answers(2));

    $this->assertSame(143, $code);
    $this->assertSame(143, $this->payload(1)['exit']);
  }

  /**
   * A command that does not exist ends with 127 and is reported.
   */
  public function testCommandThatDoesNotExistEndsWith127(): void {
    $code = $this->execute(['/no/such/command'], $this->answers(2));

    $this->assertSame(127, $code);
    $this->assertSame(127, $this->payload(1)['exit']);
  }

  /**
   * Without a key the command still runs, and the console says why.
   */
  public function testWithoutKeyTheCommandStillRuns(): void {
    new Settings(['tracker_monitoring_key' => ''] + Settings::getAll());

    $code = $this->execute(['sh', '-c', 'echo ran; exit 2'], []);

    $this->assertSame(2, $code);
    $output = $this->output->fetch();
    $this->assertStringContainsString('ran', $output);
    $this->assertStringContainsString('key is not set', $output);
    $this->assertSame([], $this->sent);
  }

  /**
   * A name Tracker would refuse is said, and the command still runs.
   */
  public function testNameTrackerWouldRefuseIsSaidAndTheCommandStillRuns(): void {
    $code = $this->execute(['sh', '-c', 'echo ran'], [], 'Queue Run');

    $this->assertSame(0, $code);
    $output = $this->output->fetch();
    $this->assertStringContainsString('ran', $output);
    $this->assertStringContainsString('is not a job name', $output);
    $this->assertSame([], $this->sent);
  }

  /**
   * A Tracker that cannot be reached never stops the job or changes its code.
   */
  public function testTrackerThatCannotBeReachedNeverStopsTheJob(): void {
    $down = new ConnectException('Connection refused', new Request('POST', 'https://tracker.example.com/hooks/job'));

    $code = $this->execute(['sh', '-c', 'echo ran; exit 5'], array_fill(0, 6, $down));

    $this->assertSame(5, $code, "The job's own exit code, not the trouble of reporting it.");
    $output = $this->output->fetch();
    $this->assertStringContainsString('ran', $output);
    $this->assertStringContainsString('Tracker could not be reached', $output);
  }

  /**
   * With no command it says how the command is used, and runs nothing.
   */
  public function testWithNoCommandItSaysHowItIsUsed(): void {
    $code = $this->execute([], []);

    $this->assertSame(Command::INVALID, $code);
    $this->assertStringContainsString('No command given', $this->output->fetch());
    $this->assertSame([], $this->sent);
  }

  /**
   * Makes the answers of a Tracker that takes everything in.
   *
   * @return array<int, \Psr\Http\Message\ResponseInterface>
   *   The responses.
   */
  private function answers(int $count): array {
    return array_fill(0, $count, new Response(200, [], '{"ok":true}'));
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
   * Runs the command with a Tracker that answers as given.
   *
   * Returns the command's exit code.
   *
   * @param string[] $command
   *   The command to run.
   * @param array<int, \Psr\Http\Message\ResponseInterface|\Throwable> $answers
   *   What Tracker answers, in order.
   * @param string $job
   *   The job's name.
   */
  private function execute(array $command, array $answers, string $job = 'queue-run'): int {
    $this->sent = [];
    $stack = HandlerStack::create(new MockHandler($answers));
    $stack->push(Middleware::history($this->sent));
    $reporter = new JobReporter(
      $this->container->get('config.factory'),
      new Client(['handler' => $stack]),
      $this->container->get('datetime.time'),
      new NullLogger(),
      0,
    );
    $this->output = new BufferedOutput();

    return (new RunCommand($reporter))(new SymfonyStyle(new ArrayInput([]), $this->output), $job, $command);
  }

}
