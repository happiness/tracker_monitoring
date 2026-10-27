<?php

declare(strict_types=1);

namespace Drupal\Tests\tracker_monitoring\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\tracker_monitoring\Key;
use Drupal\tracker_monitoring\PackageReader;
use Drupal\tracker_monitoring\Report;
use Drupal\tracker_monitoring\ReportSender;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests which reports the sender will send.
 */
#[Group('tracker_monitoring')]
#[RunTestsInSeparateProcesses]
final class ReportSenderTest extends KernelTestBase {

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
  protected static $modules = ['system', 'dblog', 'tracker_monitoring'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['tracker_monitoring']);
    $this->config('tracker_monitoring.settings')->set('endpoint', 'https://tracker.example.com/')->save();
  }

  /**
   * Nothing is sent until Tracker's address is set: there is no default.
   */
  public function testNothingIsSentWithoutTrackerAddress(): void {
    $this->config('tracker_monitoring.settings')->set('endpoint', NULL)->save();
    $this->switchOn(Report::Logs);
    $this->setKey(self::KEY);

    $result = $this->sender()->send(Report::Logs);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('address is not set', $result->message);
  }

  /**
   * A report that is switched off is not sent, with or without a key.
   */
  public function testSwitchedOffReportIsNotSent(): void {
    $this->setKey(self::KEY);

    $result = $this->sender()->send(Report::Logs);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('switched off', $result->message);
  }

  /**
   * A report that is switched on needs the key.
   */
  public function testSwitchedOnReportNeedsTheKey(): void {
    $this->switchOn(Report::Logs);

    $result = $this->sender()->send(Report::Logs);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('key is not set', $result->message);
  }

  /**
   * A report that is switched on and has a key is sent.
   */
  public function testSwitchedOnReportWithKeyIsSent(): void {
    $this->switchOn(Report::Logs);
    $this->setKey(self::KEY);

    $this->assertTrue($this->sender()->send(Report::Logs)->success);
  }

  /**
   * A key that is not one Tracker made is not used.
   */
  public function testKeyMustBeTheOneTrackerMade(): void {
    $this->switchOn(Report::Packages);
    $this->setKey('a-key');

    $this->assertStringContainsString('key is not set', $this->sender()->send(Report::Packages)->message);
  }

  /**
   * The packages are posted, signed so that Tracker can check them.
   */
  public function testPackagesArePostedAndSigned(): void {
    $this->switchOn(Report::Packages);
    $this->setKey(self::KEY);

    $result = $this->packagesSender([new Response(200, [], '{"ok":true,"vulnerable":2,"opened":1,"updated":0,"closed":0}')])->send(Report::Packages);

    $this->assertTrue($result->success);
    $this->assertSame('Sent. 2 with security advisories, 1 tickets opened, 0 updated, 0 closed.', $result->message);
    $this->assertCount(1, $this->sent);
    $request = $this->sent[0]['request'];
    $body = (string) $request->getBody();
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('https://tracker.example.com/hooks/monitoring', (string) $request->getUri());
    $this->assertSame('12', $request->getHeaderLine('X-Tracker-Project'));
    $time = (int) $request->getHeaderLine('X-Tracker-Timestamp');
    $this->assertEqualsWithDelta(time(), $time, 5);
    $this->assertSame(hash_hmac('sha256', $time . '.' . $body, self::KEY), $request->getHeaderLine('X-Tracker-Signature'));
    $payload = json_decode($body, TRUE);
    $this->assertSame('packages', $payload['report']);
    $this->assertSame('3.10.3', $payload['packages']['twig/twig']);
    $this->assertSame(['drupal/core', 'twig/twig'], $payload['requirements']['drupal/core-recommended']);
    $this->assertSame(['drupal/core-recommended', 'drupal/admin_toolbar'], $payload['requires']);
    $this->assertSame(Key::sign(self::KEY, $time, $body), $request->getHeaderLine('X-Tracker-Signature'));
  }

  /**
   * A refusal is reported as such; a Tracker that cannot answer is tried again.
   */
  public function testRefusalsAndFailuresAreTold(): void {
    $this->switchOn(Report::Packages);
    $this->setKey(self::KEY);

    $refused = $this->packagesSender([new Response(401, [], '{"error":"The project, the timestamp or the signature does not match."}')])->send(Report::Packages);
    $this->assertFalse($refused->success);
    $this->assertStringContainsString('refused the report with 401', $refused->message);
    $this->assertStringContainsString('does not match', $refused->message);

    $busy = $this->packagesSender([new Response(503, [], '{"error":"Packagist cannot be reached."}')])->send(Report::Packages);
    $this->assertFalse($busy->success);
    $this->assertStringContainsString('tried again on the next run', $busy->message);

    $down = $this->packagesSender([new ConnectException('no route', new Request('POST', 'https://tracker.example.com'))])->send(Report::Packages);
    $this->assertFalse($down->success);
    $this->assertStringContainsString('could not be reached', $down->message);
  }

  /**
   * The status is posted, signed, with what the status report says.
   */
  public function testStatusIsPostedAndSigned(): void {
    $this->switchOn(Report::Status);
    $this->setKey(self::KEY);
    $this->container->get('state')->set('system.maintenance_mode', TRUE);

    $result = $this->packagesSender([new Response(200, [], '{"ok":true,"opened":1,"updated":0,"closed":2}')])->send(Report::Status);

    $this->assertTrue($result->success, $result->message);
    $this->assertSame('Sent. 1 tickets opened, 0 updated, 2 closed.', $result->message);
    $request = $this->sent[0]['request'];
    $body = (string) $request->getBody();
    $this->assertSame(Key::sign(self::KEY, (int) $request->getHeaderLine('X-Tracker-Timestamp'), $body), $request->getHeaderLine('X-Tracker-Signature'));
    $payload = json_decode($body, TRUE);
    $this->assertSame('status', $payload['report']);
    $this->assertSame(\Drupal::VERSION, $payload['versions']['drupal']);
    $this->assertSame(PHP_VERSION, $payload['versions']['php']);
    $this->assertTrue($payload['maintenance_mode']);
    $this->assertIsArray($payload['requirements']);
    $this->assertSame([], $payload['updates'], 'the Update module is not installed');
  }

  /**
   * A site whose packages cannot be read sends nothing.
   */
  public function testUnreadablePackagesSendNothing(): void {
    $this->switchOn(Report::Packages);
    $this->setKey(self::KEY);
    $sender = new ReportSender($this->container->get('config.factory'), new Client(['handler' => new MockHandler([])]), $this->container->get('datetime.time'), new PackageReader('/nowhere'), $this->container->get('tracker_monitoring.status_collector'), $this->container->get('tracker_monitoring.log_collector'), new NullLogger());

    $result = $sender->send(Report::Packages);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('could not be read', $result->message);
  }

  /**
   * Only the reports that are switched on are listed.
   */
  public function testEnabledReports(): void {
    $this->assertSame([], $this->sender()->enabledReports());

    $this->switchOn(Report::Status);
    $this->switchOn(Report::Logs);

    $this->assertSame([Report::Status, Report::Logs], $this->sender()->enabledReports());
  }

  /**
   * Switches a report on in the settings.
   */
  private function switchOn(Report $report): void {
    $this->config('tracker_monitoring.settings')->set($report->settingKey(), TRUE)->save();
  }

  /**
   * Sets the key, as settings.php would.
   */
  private function setKey(string $key): void {
    new Settings(['tracker_monitoring_key' => $key] + Settings::getAll());
  }

  /**
   * Makes a sender whose Tracker answers with the given responses.
   *
   * @param array<int, \Psr\Http\Message\ResponseInterface|\Throwable> $answers
   *   What Tracker answers, in order.
   */
  private function packagesSender(array $answers): ReportSender {
    $this->sent = [];
    $stack = HandlerStack::create(new MockHandler($answers));
    $stack->push(Middleware::history($this->sent));
    return new ReportSender(
      $this->container->get('config.factory'),
      new Client(['handler' => $stack]),
      $this->container->get('datetime.time'),
      new PackageReader(dirname(__DIR__, 2) . '/fixtures/site'),
      $this->container->get('tracker_monitoring.status_collector'),
      $this->container->get('tracker_monitoring.log_collector'),
      new NullLogger(),
    );
  }

  /**
   * Adds a row to the database log, as dblog would.
   *
   * @param int $severity
   *   The RFC 5424 severity, 0 to 7.
   * @param string $type
   *   The log type, such as "php".
   * @param string $message
   *   The message template.
   * @param array<string, string> $variables
   *   The placeholders of the message.
   */
  private function log(int $severity, string $type, string $message, array $variables = []): void {
    $this->container->get('database')->insert('watchdog')->fields([
      'uid' => 0,
      'type' => $type,
      'message' => $message,
      'variables' => serialize($variables),
      'severity' => $severity,
      'link' => '',
      'location' => '',
      'referer' => '',
      'hostname' => '',
      'timestamp' => 1792000000,
    ])->execute();
  }

  /**
   * Gets the rows of the log reports Tracker was sent.
   *
   * @return array<int, list<array<string, mixed>>>
   *   The rows of each request, in order.
   */
  private function sentRows(): array {
    return array_map(static function (array $transaction): array {
      $body = json_decode((string) $transaction['request']->getBody(), TRUE, 8, JSON_THROW_ON_ERROR);
      return $body['rows'];
    }, $this->sent);
  }

  /**
   * The log starts at the newest row: nothing from before is sent.
   */
  public function testLogsStartAtTheNewestRowAndSendNothingFromBefore(): void {
    $this->switchOn(Report::Logs);
    $this->setKey(self::KEY);
    $this->log(3, 'php', 'An old error');

    $result = $this->packagesSender([])->send(Report::Logs);

    $this->assertTrue($result->success);
    $this->assertStringContainsString('Started', $result->message);
    $this->assertSame([], $this->sent, 'no request was made');
    $this->assertSame(1, $this->container->get('tracker_monitoring.log_collector')->cursor());
  }

  /**
   * New rows are sent once, filtered, with a level and a scrubbed message.
   */
  public function testNewRowsAreSentOnceFilteredAndScrubbed(): void {
    $this->switchOn(Report::Logs);
    $this->setKey(self::KEY);
    $this->packagesSender([])->send(Report::Logs);
    $this->log(4, 'mail', 'Mail to @name failed from 10.0.0.1', ['@name' => 'bob@example.com']);
    $this->log(3, 'php', 'Notice at /node/1?destination=/admin');
    $this->log(4, 'page not found', 'node/99');
    $this->log(5, 'cron', 'Cron run completed');

    $sender = $this->packagesSender([new Response(200, [], '{"ok":true,"accepted":2,"dropped":0}')]);
    $result = $sender->send(Report::Logs);

    $this->assertTrue($result->success);
    $this->assertSame('Sent 2 log rows.', $result->message);
    $rows = $this->sentRows()[0];
    $this->assertCount(2, $rows, 'a notice and the excluded type are left out');
    $this->assertSame('mail', $rows[0]['type']);
    $this->assertSame('warning', $rows[0]['level']);
    $this->assertSame('Mail to @name failed from <ip>', $rows[0]['message'], 'the template, not the filled-in message');
    $this->assertSame('php', $rows[1]['type']);
    $this->assertSame('error', $rows[1]['level']);
    $this->assertSame('Notice at /node/1?<query>', $rows[1]['message']);
    $this->assertSame(1792000000, $rows[0]['time']);

    $again = $this->packagesSender([]);
    $this->assertSame('No new log rows.', $again->send(Report::Logs)->message);
    $this->assertSame([], $this->sent, 'the same rows are not sent twice');
  }

  /**
   * The filled-in message is sent only when the settings say so, and scrubbed.
   */
  public function testTheFilledInMessageIsSentOnlyWhenAskedForAndIsScrubbed(): void {
    $this->switchOn(Report::Logs);
    $this->config('tracker_monitoring.settings')->set('log_send_filled_message', TRUE)->save();
    $this->setKey(self::KEY);
    $this->packagesSender([])->send(Report::Logs);
    $this->log(4, 'mail', 'Mail to @name failed', ['@name' => 'bob@example.com']);

    $this->packagesSender([new Response(200, [], '{"ok":true,"accepted":1,"dropped":0}')])->send(Report::Logs);

    $this->assertSame('Mail to <email> failed', $this->sentRows()[0][0]['message']);
  }

  /**
   * Rows Tracker did not take in are sent again, and the cursor stays put.
   */
  public function testRefusedBatchIsSentAgain(): void {
    $this->switchOn(Report::Logs);
    $this->setKey(self::KEY);
    $this->packagesSender([])->send(Report::Logs);
    $this->log(3, 'php', 'Boom');

    $failed = $this->packagesSender([new Response(503, [], '{"error":"The log server is down."}')])->send(Report::Logs);
    $this->assertFalse($failed->success);
    $this->assertStringContainsString('503', $failed->message);
    $this->assertSame(0, $this->container->get('tracker_monitoring.log_collector')->cursor(), 'not moved');

    $sender = $this->packagesSender([new Response(200, [], '{"ok":true,"accepted":1,"dropped":0}')]);
    $this->assertTrue($sender->send(Report::Logs)->success);
    $this->assertSame('Boom', $this->sentRows()[0][0]['message']);
    $this->assertSame(1, $this->container->get('tracker_monitoring.log_collector')->cursor());
  }

  /**
   * A backlog goes out in batches of the size Tracker takes.
   */
  public function testBacklogIsSentInBatchesOfOneThousand(): void {
    $this->switchOn(Report::Logs);
    $this->setKey(self::KEY);
    $this->packagesSender([])->send(Report::Logs);
    for ($i = 0; $i < 1001; $i++) {
      $this->log(3, 'php', 'Boom');
    }

    $ok = new Response(200, [], '{"ok":true,"accepted":1,"dropped":0}');
    $result = $this->packagesSender([$ok, $ok])->send(Report::Logs);

    $this->assertSame('Sent 1001 log rows.', $result->message);
    $this->assertSame([1000, 1], array_map('count', $this->sentRows()));
  }

  /**
   * Gets the service under test.
   */
  private function sender(): ReportSender {
    return $this->container->get('tracker_monitoring.report_sender');
  }

}
