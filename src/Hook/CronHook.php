<?php

declare(strict_types=1);

namespace Drupal\tracker_monitoring\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\tracker_monitoring\ReportScheduler;

/**
 * Sends the reports from cron.
 */
final class CronHook {

  public function __construct(
    private readonly ReportScheduler $scheduler,
  ) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->scheduler->run();
  }

}
