<?php

declare(strict_types=1);

namespace Drupal\tracker_monitoring\Command;

use Drupal\tracker_monitoring\Report;
use Drupal\tracker_monitoring\ReportSender;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends the reports that are switched on to Tracker.
 *
 * Run from the system's cron: `dr tracker-monitoring:send`.
 */
#[AsCommand(
  name: 'tracker-monitoring:send',
  description: 'Sends packages, status and log rows to Tracker.',
)]
final class SendCommand {

  public function __construct(
    private readonly ReportSender $sender,
  ) {}

  /**
   * Sends one report, or every report that is switched on.
   */
  public function __invoke(
    SymfonyStyle $io,
    #[Argument('What to send: packages, status, logs or all. Only reports switched on in the settings are sent.')]
    string $report = 'all',
  ): int {
    $reports = $report === 'all' ? $this->sender->enabledReports() : array_filter([Report::tryFrom($report)]);
    if ($report !== 'all' && $reports === []) {
      $io->error(sprintf('Unknown report "%s". Use packages, status, logs or all.', $report));
      return Command::INVALID;
    }
    if ($reports === []) {
      $io->note('No report is switched on in the settings. Nothing sent.');
      return Command::SUCCESS;
    }

    $status = Command::SUCCESS;
    foreach ($reports as $item) {
      $result = $this->sender->send($item);
      $io->writeln(sprintf('%s: %s', $result->report->value, $result->message));
      if (!$result->success) {
        $status = Command::FAILURE;
      }
    }
    return $status;
  }

}
