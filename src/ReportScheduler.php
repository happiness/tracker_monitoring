<?php

declare(strict_types=1);

namespace Drupal\tracker_monitoring;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;

/**
 * Decides, on each cron run, which reports are due and sends them.
 *
 * A report that was sent is sent again after its interval. One that failed is
 * tried again after an hour, so a Tracker that is down is not asked on every
 * cron run and a replaced key is picked up without anyone running a command.
 * The command `tracker-monitoring:send` sends at once and does not move these
 * times.
 */
final class ReportScheduler {

  /**
   * Seconds to wait after a report that did not go through.
   */
  public const RETRY_AFTER = 3600;

  public function __construct(
    private readonly ReportSender $sender,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Sends the reports that are switched on and due.
   *
   * @return \Drupal\tracker_monitoring\SendResult[]
   *   What came of each report that was sent.
   */
  public function run(): array {
    $now = $this->time->getRequestTime();
    $results = [];
    foreach ($this->sender->enabledReports() as $report) {
      $interval = $report->interval();
      if ($now < (int) $this->state->get($this->stateKey($report), 0)) {
        continue;
      }
      $result = $this->sender->send($report);
      $this->state->set($this->stateKey($report), $now + ($result->success ? $interval : self::RETRY_AFTER));
      $results[] = $result;
    }
    return $results;
  }

  /**
   * Gets the state key that holds when a report is next due.
   */
  private function stateKey(Report $report): string {
    return 'tracker_monitoring.next_send.' . $report->value;
  }

}
