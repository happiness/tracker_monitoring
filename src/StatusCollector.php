<?php

declare(strict_types=1);

namespace Drupal\tracker_monitoring;

use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\State\StateInterface;
use Drupal\system\SystemManager;
use Drupal\update\UpdateManagerInterface;

/**
 * Collects what the site's status report says.
 *
 * Only what has a clear action for a person is sent: the warnings and errors of
 * the status report, maintenance mode left on, the updates Drupal's Update
 * module knows about, and the versions that tell which site this is.
 */
final class StatusCollector {

  /**
   * The longest text sent for one requirement.
   */
  private const MAX_TEXT = 500;

  public function __construct(
    private readonly SystemManager $systemManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly StateInterface $state,
    private readonly RendererInterface $renderer,
    private readonly Connection $database,
  ) {}

  /**
   * Collects the status.
   *
   * @return array{versions: array<string, string>, maintenance_mode: bool, requirements: list<array<string, string>>, updates: list<array<string, string>>}
   *   The status, as Tracker takes it in.
   */
  public function collect(): array {
    $updates = $this->updates();
    return [
      'versions' => [
        'drupal' => \Drupal::VERSION,
        'php' => PHP_VERSION,
        'database' => $this->database->databaseType() . ' ' . $this->database->version(),
      ],
      'maintenance_mode' => (bool) $this->state->get('system.maintenance_mode', FALSE),
      'requirements' => $this->requirements($updates !== []),
      'updates' => $updates,
    ];
  }

  /**
   * Gets the warnings and errors of the status report.
   *
   * @param bool $updatesKnown
   *   Whether the updates are sent as well: the Update module's own
   *   requirements then say the same thing twice and are left out.
   *
   * @return list<array<string, string>>
   *   Id, title, value, description and severity ("warning" or "error").
   */
  private function requirements(bool $updatesKnown): array {
    $found = [];
    foreach ($this->systemManager->listRequirements() as $id => $requirement) {
      $severity = $requirement['severity'] ?? 0;
      $level = $severity instanceof RequirementSeverity ? $severity->value : (int) $severity;
      if ($level < RequirementSeverity::Warning->value) {
        continue;
      }
      if ($updatesKnown && in_array($id, ['update_core', 'update_contrib'], TRUE)) {
        continue;
      }
      $found[] = [
        'id' => (string) $id,
        'title' => $this->text($requirement['title'] ?? $id, 200),
        'value' => $this->text($requirement['value'] ?? '', 200),
        'description' => $this->text($requirement['description'] ?? '', self::MAX_TEXT),
        'severity' => $level >= RequirementSeverity::Error->value ? 'error' : 'warning',
      ];
    }
    return $found;
  }

  /**
   * Gets the projects the Update module says are not current.
   *
   * Nothing is fetched here: the Update module refreshes its own data from
   * cron, and this reads what it has.
   *
   * @return list<array<string, string>>
   *   Name, title, installed and recommended version, and why it is listed:
   *   "security", "revoked", "unsupported" or "not_current".
   */
  private function updates(): array {
    if (!$this->moduleHandler->moduleExists('update')) {
      return [];
    }
    $this->moduleHandler->loadInclude('update', 'module');
    $this->moduleHandler->loadInclude('update', 'inc', 'update.compare');
    $available = update_get_available(FALSE);
    if (!is_array($available) || $available === []) {
      return [];
    }
    $reasons = [
      UpdateManagerInterface::NOT_SECURE => 'security',
      UpdateManagerInterface::REVOKED => 'revoked',
      UpdateManagerInterface::NOT_SUPPORTED => 'unsupported',
      UpdateManagerInterface::NOT_CURRENT => 'not_current',
    ];
    $found = [];
    foreach (update_calculate_project_data($available) as $name => $project) {
      $reason = $reasons[$project['status'] ?? NULL] ?? NULL;
      if ($reason === NULL) {
        continue;
      }
      $found[] = [
        'name' => (string) $name,
        'title' => $this->text($project['title'] ?? $name, 200),
        'installed' => (string) ($project['existing_version'] ?? ''),
        'recommended' => (string) ($project['recommended'] ?? ''),
        'reason' => $reason,
      ];
    }
    return $found;
  }

  /**
   * Makes a piece of a requirement into short plain text.
   *
   * Requirements give text, markup objects or render arrays.
   */
  private function text(mixed $value, int $max): string {
    if (is_array($value)) {
      $value = (string) $this->renderer->renderInIsolation($value);
    }
    $text = is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES)));
    return mb_substr($text, 0, $max);
  }

}
