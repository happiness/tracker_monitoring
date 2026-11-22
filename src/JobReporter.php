<?php

declare(strict_types=1);

namespace Drupal\tracker_monitoring;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Tells Tracker when a job starts and how it ended.
 *
 * A job is a command that runs outside the site's own code, usually a line in
 * the crontab. Tracker opens an internal ticket for a job that fails, does not
 * start on its schedule or runs too long. Reports here never raise an
 * exception: reporting must not get in the way of the job.
 *
 * A report is tried three times when Tracker is busy or cannot be reached; the
 * last constructor argument is the pause in microseconds before the second
 * try, and three times that before the third (the tests set it to 0).
 */
final class JobReporter {

  /**
   * What a job's name looks like; Tracker refuses any other.
   */
  public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,99}$/';

  /**
   * How many bytes of the end of a failed run's output are sent.
   */
  public const MAX_OUTPUT = 10000;

  /**
   * How many times a report is tried when Tracker is busy or cannot be reached.
   */
  private const ATTEMPTS = 3;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly int $pause = 1000000,
  ) {}

  /**
   * Says why a job cannot be reported, or NULL when it can.
   */
  public function problem(string $job): ?string {
    if (preg_match(self::SLUG_PATTERN, $job) !== 1) {
      return sprintf('"%s" is not a job name (1 to 100 lowercase letters, digits, "-" or "_").', $job);
    }
    if (Tracker::endpoint($this->configFactory) === '') {
      return "Tracker's address is not set. See the Tracker Monitoring settings.";
    }
    if (!Key::isSet()) {
      return 'The key is not set, or is not one Tracker made ("<number>.<secret>").';
    }
    return NULL;
  }

  /**
   * Tells Tracker that a run starts.
   *
   * @return string|null
   *   What went wrong, or NULL when Tracker took it in.
   */
  public function start(string $job): ?string {
    return $this->post(['job' => $job, 'event' => 'start']);
  }

  /**
   * Tells Tracker how a run ended.
   *
   * @param string $job
   *   The job's name.
   * @param int $exitCode
   *   The exit code, 0 to 255.
   * @param int $seconds
   *   How long the run took.
   * @param string $output
   *   The end of what the command printed. It is sent for a failed run only:
   *   what a successful run prints is of no use and may be private.
   *
   * @return string|null
   *   What went wrong, or NULL when Tracker took it in.
   */
  public function finish(string $job, int $exitCode, int $seconds, string $output): ?string {
    $payload = ['job' => $job, 'event' => 'finish', 'exit' => $exitCode, 'seconds' => $seconds];
    if ($exitCode !== 0) {
      $payload['output'] = mb_strcut($output, max(0, strlen($output) - self::MAX_OUTPUT));
    }
    return $this->post($payload);
  }

  /**
   * Posts one signed report, with a few tries for a Tracker that is busy.
   *
   * The timestamp is the time of sending, not of the request: a job can run
   * for hours, and Tracker refuses a timestamp more than five minutes off.
   *
   * @param array<string, mixed> $payload
   *   The report.
   *
   * @return string|null
   *   What went wrong, or NULL when Tracker took it in.
   */
  private function post(array $payload): ?string {
    $key = Key::get();
    $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
    $body = json_encode($payload, $flags);
    $message = '';
    for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
      $now = $this->time->getCurrentTime();
      try {
        $response = $this->httpClient->request('POST', Tracker::jobUrl($this->configFactory), [
          'headers' => [
            'Content-Type' => 'application/json',
            'X-Tracker-Project' => (string) Key::projectId($key),
            'X-Tracker-Timestamp' => (string) $now,
            'X-Tracker-Signature' => Key::sign($key, $now, $body),
          ],
          'body' => $body,
          'http_errors' => FALSE,
          'timeout' => 10,
        ]);
        $status = $response->getStatusCode();
        if ($status === 200) {
          return NULL;
        }
        $answer = json_decode((string) $response->getBody(), TRUE);
        $said = is_array($answer) && is_string($answer['error'] ?? NULL) ? $answer['error'] : 'No reason given.';
        $message = sprintf('Tracker answered %d (%s).', $status, $said);
        if ($status !== 429 && $status < 500) {
          // A refusal will not change by asking again.
          $this->logger->error('Tracker refused a job report with @status: @said', [
            '@status' => $status,
            '@said' => $said,
          ]);
          return $message;
        }
      }
      catch (GuzzleException $e) {
        $message = 'Tracker could not be reached: ' . $e->getMessage();
      }
      if ($attempt + 1 < self::ATTEMPTS) {
        usleep($this->pause * (1 + 2 * $attempt));
      }
    }
    $this->logger->warning('A job report was not delivered: @message', ['@message' => $message]);
    return $message;
  }

}
