<?php

declare(strict_types=1);

namespace Drupal\tracker_monitoring;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Sends reports to Tracker.
 *
 * The Drupal CLI command and, later, other callers (an MCP tool) use this and
 * hold no logic of their own.
 */
final class ReportSender {

  /**
   * The most log batches one run sends.
   *
   * A long backlog is worked off over several runs, not in one request.
   */
  private const MAX_LOG_BATCHES = 5;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly TimeInterface $time,
    private readonly PackageReader $packageReader,
    private readonly StatusCollector $statusCollector,
    private readonly LogCollector $logCollector,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Gets the reports that are switched on in the settings.
   *
   * @return \Drupal\tracker_monitoring\Report[]
   *   The reports.
   */
  public function enabledReports(): array {
    return array_values(array_filter(Report::cases(), $this->isEnabled(...)));
  }

  /**
   * Sends one report, if it is switched on in the settings.
   */
  public function send(Report $report): SendResult {
    if (!$this->isEnabled($report)) {
      return new SendResult($report, FALSE, 'The report is switched off in the settings.');
    }
    if (Tracker::endpoint($this->configFactory) === '') {
      return new SendResult($report, FALSE, "Tracker's address is not set. See the Tracker Monitoring settings.");
    }
    if (!Key::isSet()) {
      return new SendResult($report, FALSE, 'The key is not set, or is not one Tracker made ("<number>.<secret>"). See the Tracker Monitoring settings.');
    }

    return match ($report) {
      Report::Packages => $this->sendPackages(),
      Report::Status => $this->post(Report::Status, ['report' => 'status'] + $this->statusCollector->collect()),
      Report::Logs => $this->sendLogs(),
    };
  }

  /**
   * Sends the new rows of the database log, in batches.
   *
   * The first send only marks where to start: the log of a site is not sent
   * back in time. The cursor moves only after Tracker took a batch in, so
   * rows are sent again after a failure and never twice after a success.
   */
  private function sendLogs(): SendResult {
    if (!$this->logCollector->isAvailable()) {
      return new SendResult(Report::Logs, FALSE, 'The Database Logging module (dblog) is not installed: there are no log rows to send.');
    }
    if ($this->logCollector->cursor() === NULL) {
      $this->logCollector->start();
      return new SendResult(Report::Logs, TRUE, 'Started: log rows from now on are sent, nothing from before.');
    }

    $sent = 0;
    for ($batch = 0; $batch < self::MAX_LOG_BATCHES; $batch++) {
      $next = $this->logCollector->next();
      if ($next['rows'] !== []) {
        $result = $this->post(Report::Logs, ['report' => 'logs', 'rows' => $next['rows']]);
        if (!$result->success) {
          return new SendResult(Report::Logs, FALSE, $sent > 0 ? sprintf('%s %d rows were sent before that.', $result->message, $sent) : $result->message);
        }
        $sent += count($next['rows']);
      }
      $this->logCollector->advance($next['last']);
      if (count($next['rows']) < LogCollector::BATCH) {
        break;
      }
    }
    return new SendResult(Report::Logs, TRUE, $sent === 0 ? 'No new log rows.' : sprintf('Sent %d log rows.', $sent));
  }

  /**
   * Sends the installed packages.
   */
  private function sendPackages(): SendResult {
    try {
      $packages = $this->packageReader->read();
    }
    catch (\RuntimeException $e) {
      $this->logger->error('The packages could not be read: @message', ['@message' => $e->getMessage()]);
      return new SendResult(Report::Packages, FALSE, 'The packages could not be read: ' . $e->getMessage());
    }
    return $this->post(Report::Packages, [
      'report' => 'packages',
      'packages' => $packages['packages'],
      // An empty list must be an object, as Tracker expects a map.
      'requirements' => (object) $packages['requirements'],
      'requires' => $packages['requires'],
    ]);
  }

  /**
   * Posts a signed report and says what Tracker answered.
   *
   * Tracker changes nothing when it answers 429 or 5xx, so those, and a
   * Tracker that cannot be reached, are tried again on the next run. A refusal
   * (400, 401, 409, 413) will not change by sending the same again until a
   * person changes the key or the project's level: it is logged for them to
   * read. Cron still tries once an hour, which costs nothing and picks the
   * change up.
   *
   * @param \Drupal\tracker_monitoring\Report $report
   *   Which report it is.
   * @param array<string, mixed> $payload
   *   The report.
   */
  private function post(Report $report, array $payload): SendResult {
    $key = Key::get();
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $now = $this->time->getRequestTime();
    try {
      $response = $this->httpClient->request('POST', Tracker::reportUrl($this->configFactory), [
        'headers' => [
          'Content-Type' => 'application/json',
          'X-Tracker-Project' => (string) Key::projectId($key),
          'X-Tracker-Timestamp' => (string) $now,
          'X-Tracker-Signature' => Key::sign($key, $now, $body),
        ],
        'body' => $body,
        'http_errors' => FALSE,
        'timeout' => 30,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->warning('Tracker could not be reached: @message', ['@message' => $e->getMessage()]);
      return new SendResult($report, FALSE, 'Tracker could not be reached. It is tried again on the next run.');
    }

    $status = $response->getStatusCode();
    $answer = json_decode((string) $response->getBody(), TRUE);
    $answer = is_array($answer) ? $answer : [];
    if ($status === 200) {
      $counts = [(int) ($answer['opened'] ?? 0), (int) ($answer['updated'] ?? 0), (int) ($answer['closed'] ?? 0)];
      if ($report === Report::Logs) {
        return new SendResult($report, TRUE, sprintf('Sent. %d rows accepted, %d dropped.', (int) ($answer['accepted'] ?? 0), (int) ($answer['dropped'] ?? 0)));
      }
      if ($report === Report::Packages) {
        $format = 'Sent. %d with security advisories, %d tickets opened, %d updated, %d closed.';
        array_unshift($counts, (int) ($answer['vulnerable'] ?? 0));
      }
      else {
        $format = 'Sent. %d tickets opened, %d updated, %d closed.';
      }
      return new SendResult($report, TRUE, sprintf($format, ...$counts));
    }

    $said = is_string($answer['error'] ?? NULL) ? $answer['error'] : 'No reason given.';
    if ($status === 429 || $status >= 500) {
      $this->logger->warning('Tracker answered @status: @said', ['@status' => $status, '@said' => $said]);
      $format = 'Tracker answered %d (%s). Nothing was changed; it is tried again on the next run.';
      return new SendResult($report, FALSE, sprintf($format, $status, $said));
    }
    $this->logger->error('Tracker refused the @report report with @status: @said', [
      '@report' => $report->value,
      '@status' => $status,
      '@said' => $said,
    ]);
    $format = "Tracker refused the report with %d (%s). Check the key and the project's level in Tracker.";
    return new SendResult($report, FALSE, sprintf($format, $status, $said));
  }

  /**
   * Whether the settings switch the report on.
   */
  private function isEnabled(Report $report): bool {
    return (bool) $this->configFactory->get('tracker_monitoring.settings')->get($report->settingKey());
  }

}
