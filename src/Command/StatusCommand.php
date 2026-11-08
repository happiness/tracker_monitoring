<?php

declare(strict_types=1);

namespace Drupal\tracker_monitoring\Command;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\tracker_monitoring\Key;
use Drupal\tracker_monitoring\LogCollector;
use Drupal\tracker_monitoring\Report;
use Drupal\tracker_monitoring\ReportScheduler;
use Drupal\tracker_monitoring\ReportSender;
use Drupal\tracker_monitoring\Tracker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows the settings and how sending went, for finding out why a site is quiet.
 *
 * The key is never shown, only whether it is set. Changes nothing.
 */
#[AsCommand(
  name: 'tracker-monitoring:status',
  description: 'Shows the settings (without the key), the last log row sent and what Tracker last answered.',
)]
final class StatusCommand {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ReportSender $sender,
    private readonly ReportScheduler $scheduler,
    private readonly LogCollector $logCollector,
  ) {}

  /**
   * Prints the settings, then each report and the log cursor.
   */
  public function __invoke(SymfonyStyle $io): int {
    $config = $this->configFactory->get('tracker_monitoring.settings');
    $endpoint = Tracker::endpoint($this->configFactory);
    $io->definitionList(
      ['Tracker address' => $endpoint === '' ? 'not set: nothing is sent' : $endpoint],
      ['Key' => Key::isSet() ? sprintf('set, for project %s', Key::projectId(Key::get())) : 'not set, or not one Tracker made'],
    );

    $rows = [];
    foreach (Report::cases() as $report) {
      $on = (bool) $config->get($report->settingKey());
      $last = $this->sender->lastResult($report);
      $next = $this->scheduler->nextDue($report);
      $rows[] = [
        $report->value,
        $on ? 'on' : 'off',
        $last === NULL ? 'never' : sprintf('%s, %s: %s', self::time($last['time']), $last['success'] ? 'OK' : 'failed', $last['message']),
        $on ? ($next === NULL ? 'next cron run' : self::time($next)) : '',
      ];
    }
    $io->table(['Report', 'Switched', 'Last sent', 'Cron sends next'], $rows);

    $io->definitionList(...$this->logs($config->get('log_min_severity'), $config->get('log_excluded_types'), (bool) $config->get('log_send_filled_message')));
    return Command::SUCCESS;
  }

  /**
   * Describes the log settings and how far the cursor has come.
   *
   * @return list<array<string, string>>
   *   The lines of the definition list.
   */
  private function logs(mixed $minSeverity, mixed $excluded, bool $filled): array {
    if (!$this->logCollector->isAvailable()) {
      return [['Log rows' => 'the Database Logging module (dblog) is not installed']];
    }
    $cursor = $this->logCollector->cursor();
    $newest = $this->logCollector->newest();
    $types = array_filter(is_array($excluded) ? $excluded : [], 'is_string');
    return [
      ['Last log row sent' => $cursor === NULL ? 'not started: the first send marks where to start' : sprintf('wid %d (the newest is %d)', $cursor, $newest)],
      ['Lowest severity sent' => (string) (is_int($minSeverity) ? $minSeverity : 4)],
      ['Types left out' => $types === [] ? 'none' : implode(', ', $types)],
      ['Message' => $filled ? 'filled in, then scrubbed' : 'the template (PHP errors with where in the code)'],
    ];
  }

  /**
   * Formats a Unix time for the console, in the site's time zone.
   */
  private static function time(int $time): string {
    return date('Y-m-d H:i:s T', $time);
  }

}
