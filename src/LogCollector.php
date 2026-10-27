<?php

declare(strict_types=1);

namespace Drupal\tracker_monitoring;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;

/**
 * Reads new rows of the database log (dblog, the watchdog table) to send.
 *
 * A cursor, the last log row id (wid) that was sent, is kept in state, so each
 * row is sent once. It starts at the newest row the first time: the history of
 * a site is not sent. The message is the template ("Mail to @name failed")
 * unless the settings ask for it filled in, and is scrubbed before it leaves
 * the site.
 */
final class LogCollector {

  /**
   * Most rows in one report, which is what Tracker takes.
   */
  public const BATCH = 1000;

  /**
   * The longest message sent.
   */
  private const MAX_MESSAGE = 2000;

  /**
   * The state key of the cursor.
   */
  private const CURSOR = 'tracker_monitoring.log_cursor';

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
  ) {}

  /**
   * Whether the site has a database log to read.
   */
  public function isAvailable(): bool {
    return $this->database->schema()->tableExists('watchdog');
  }

  /**
   * Gets the last row id that was sent, or NULL if no send has started.
   */
  public function cursor(): ?int {
    $cursor = $this->state->get(self::CURSOR);
    return is_int($cursor) ? $cursor : NULL;
  }

  /**
   * Starts at the newest row: what is in the log now is not sent.
   */
  public function start(): void {
    $this->state->set(self::CURSOR, $this->newest());
  }

  /**
   * Moves the cursor to a row id once the rows up to it were taken in.
   */
  public function advance(int $wid): void {
    $this->state->set(self::CURSOR, $wid);
  }

  /**
   * Gets the next rows to send, after the cursor.
   *
   * @return array{rows: list<array{time: int, type: string, level: string, message: string}>, last: int}
   *   The rows that pass the settings, and the row id the cursor moves to when
   *   they are sent: the last row of a full batch, otherwise the newest row
   *   there was when the log was read (rows that do not pass are skipped).
   */
  public function next(): array {
    $cursor = (int) $this->cursor();
    $newest = $this->newest();
    if ($newest <= $cursor) {
      return ['rows' => [], 'last' => $cursor];
    }

    $config = $this->configFactory->get('tracker_monitoring.settings');
    $query = $this->database->select('watchdog', 'w')
      ->fields('w', ['wid', 'type', 'message', 'variables', 'severity', 'timestamp'])
      ->condition('w.wid', $cursor, '>')
      ->condition('w.wid', $newest, '<=')
      ->condition('w.severity', (int) $config->get('log_min_severity'), '<=')
      ->orderBy('w.wid')
      ->range(0, self::BATCH);
    $excluded = array_values(array_filter((array) $config->get('log_excluded_types'), 'is_string'));
    if ($excluded !== []) {
      $query->condition('w.type', $excluded, 'NOT IN');
    }
    $filled = (bool) $config->get('log_send_filled_message');

    $rows = [];
    $last = $newest;
    foreach ($query->execute() as $row) {
      $rows[] = [
        'time' => (int) $row->timestamp,
        'type' => mb_substr((string) $row->type, 0, 100),
        'level' => self::level((int) $row->severity),
        'message' => $this->message((string) $row->message, (string) $row->variables, $filled),
      ];
      $last = (int) $row->wid;
    }
    // A full batch ends at its last row; a shorter one has used all there was.
    return ['rows' => $rows, 'last' => count($rows) === self::BATCH ? $last : $newest];
  }

  /**
   * Gets the id of the newest log row, or 0 if the log is empty.
   */
  private function newest(): int {
    return (int) $this->database->query('SELECT MAX([wid]) FROM {watchdog}')->fetchField();
  }

  /**
   * Makes the text of a row: the template or the filled-in message, scrubbed.
   */
  private function message(string $template, string $variables, bool $filled): string {
    if ($filled) {
      $values = @unserialize($variables, ['allowed_classes' => FALSE]);
      if (is_array($values)) {
        $template = strtr($template, array_map(static fn ($value): string => is_scalar($value) ? (string) $value : '', $values));
      }
    }
    $text = Html::decodeEntities(strip_tags($template));
    return mb_substr(LogScrubber::message($text), 0, self::MAX_MESSAGE);
  }

  /**
   * Maps a Drupal (RFC 5424) severity to the level Tracker takes.
   */
  private static function level(int $severity): string {
    return match (TRUE) {
      $severity <= 3 => 'error',
      $severity === 4 => 'warning',
      $severity === 5 => 'notice',
      $severity === 6 => 'info',
      default => 'debug',
    };
  }

}
