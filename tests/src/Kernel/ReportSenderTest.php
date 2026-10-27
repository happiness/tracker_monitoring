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
  protected static $modules = ['system', 'tracker_monitoring'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
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
    $sender = new ReportSender($this->container->get('config.factory'), new Client(['handler' => new MockHandler([])]), $this->container->get('datetime.time'), new PackageReader('/nowhere'), $this->container->get('tracker_monitoring.status_collector'), new NullLogger());

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
      new NullLogger(),
    );
  }

  /**
   * Gets the service under test.
   */
  private function sender(): ReportSender {
    return $this->container->get('tracker_monitoring.report_sender');
  }

}
