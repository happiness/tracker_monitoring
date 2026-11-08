<?php

declare(strict_types=1);

namespace Drupal\Tests\tracker_monitoring\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\tracker_monitoring\Command\StatusCommand;
use Drupal\tracker_monitoring\PackageReader;
use Drupal\tracker_monitoring\Report;
use Drupal\tracker_monitoring\ReportSender;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tests what tracker-monitoring:status shows.
 */
#[Group('tracker_monitoring')]
#[RunTestsInSeparateProcesses]
final class StatusCommandTest extends KernelTestBase {

  private const KEY = '12.0123456789abcdef';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'dblog', 'tracker_monitoring'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['tracker_monitoring']);
  }

  /**
   * A site that was never set up says what is missing.
   */
  public function testNothingSetUpSaysWhatIsMissing(): void {
    $output = $this->runStatus();

    $this->assertStringContainsString('not set: nothing is sent', $output);
    $this->assertStringContainsString('not set, or not one Tracker made', $output);
    $this->assertMatchesRegularExpression('/logs\s+off\s+never/', $output);
    $this->assertStringContainsString('not started', $output);
  }

  /**
   * After sending, it shows the cursor and what Tracker answered, not the key.
   */
  public function testShowsTheLastAnswerAndTheCursorButNotTheKey(): void {
    $this->config('tracker_monitoring.settings')
      ->set('endpoint', 'https://tracker.example.com/')
      ->set(Report::Packages->settingKey(), TRUE)
      ->set(Report::Logs->settingKey(), TRUE)
      ->save();
    new Settings(['tracker_monitoring_key' => self::KEY] + Settings::getAll());
    $sender = $this->sender([new Response(401, [], '{"error":"Bad signature."}')]);
    $sender->send(Report::Logs);
    $sender->send(Report::Packages);

    $output = $this->runStatus();

    $this->assertStringContainsString('set, for project 12', $output);
    $this->assertStringNotContainsString('0123456789abcdef', $output, 'the secret is never shown');
    $this->assertStringContainsString('https://tracker.example.com', $output);
    $this->assertMatchesRegularExpression('/packages\s+on\s+\S+ \S+ \S+, failed: Tracker refused the report with 401 \(Bad signature\.\)/', $output);
    $this->assertMatchesRegularExpression('/logs\s+on\s+\S+ \S+ \S+, OK: Started/', $output);
    $this->assertStringContainsString('next cron run', $output, 'sending at once does not move when cron sends');
    $this->assertStringContainsString('wid 0 (the newest is 0)', $output);
    $this->assertStringContainsString('page not found, access denied, seckit, csp', $output);
  }

  /**
   * Runs the command and gives back what it printed.
   */
  private function runStatus(): string {
    $output = new BufferedOutput();
    $command = new StatusCommand(
      $this->container->get('config.factory'),
      $this->container->get('tracker_monitoring.report_sender'),
      $this->container->get('tracker_monitoring.report_scheduler'),
      $this->container->get('tracker_monitoring.log_collector'),
    );
    $this->assertSame(Command::SUCCESS, $command(new SymfonyStyle(new ArrayInput([]), $output)));
    return $output->fetch();
  }

  /**
   * Makes a sender that talks to a mocked Tracker and shares the site's state.
   *
   * @param \Psr\Http\Message\ResponseInterface[] $answers
   *   What Tracker answers, in order.
   */
  private function sender(array $answers): ReportSender {
    return new ReportSender(
      $this->container->get('config.factory'),
      new Client(['handler' => new MockHandler($answers)]),
      $this->container->get('datetime.time'),
      new PackageReader(dirname(__DIR__, 3) . '/tests/fixtures/site'),
      $this->container->get('tracker_monitoring.status_collector'),
      $this->container->get('tracker_monitoring.log_collector'),
      new NullLogger(),
      $this->container->get('state'),
    );
  }

}
