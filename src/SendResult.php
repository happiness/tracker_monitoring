<?php

declare(strict_types=1);

namespace Drupal\tracker_monitoring;

/**
 * What came of sending one report.
 */
final readonly class SendResult {

  public function __construct(
    public Report $report,
    public bool $success,
    public string $message,
  ) {}

}
