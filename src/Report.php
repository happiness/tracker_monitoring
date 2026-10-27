<?php

declare(strict_types=1);

namespace Drupal\tracker_monitoring;

/**
 * The kinds of report the module sends.
 */
enum Report: string {

  case Packages = 'packages';
  case Status = 'status';
  case Logs = 'logs';

  /**
   * The setting that switches this report on.
   */
  public function settingKey(): string {
    return 'send_' . $this->value;
  }

  /**
   * How many seconds there are between two runs of cron sending this report.
   *
   * Tracker expects the packages daily and counts a site silent after three
   * days. The log rows are sent every hour, when there are new ones.
   */
  public function interval(): int {
    return match ($this) {
      self::Packages, self::Status => 86400,
      self::Logs => 3600,
    };
  }

}
