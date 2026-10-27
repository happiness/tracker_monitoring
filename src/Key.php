<?php

declare(strict_types=1);

namespace Drupal\tracker_monitoring;

use Drupal\Core\Site\Settings;

/**
 * The site's key for Tracker.
 *
 * It is never stored in configuration: it comes from settings.php or the
 * environment, so it does not end up in the site's repository. Tracker makes
 * it as "<project id>.<secret>"; the whole string is what reports are
 * signed with, and the part before the dot tells Tracker which project a
 * report is for.
 */
final class Key {

  /**
   * Gets the key, or an empty string when none is set.
   */
  public static function get(): string {
    return (string) Settings::get('tracker_monitoring_key', getenv('TRACKER_MONITORING_KEY') ?: '');
  }

  /**
   * Whether a usable key is available.
   */
  public static function isSet(): bool {
    return self::projectId(self::get()) !== NULL;
  }

  /**
   * Gets the project id a key belongs to, or NULL if the key is not one.
   */
  public static function projectId(string $key): ?string {
    return preg_match('/^(\d+)\.[0-9a-f]+$/', $key, $match) === 1 ? $match[1] : NULL;
  }

  /**
   * Signs a body the way Tracker checks it: HMAC-SHA256 of "<time>.<body>".
   */
  public static function sign(string $key, int $time, string $body): string {
    return hash_hmac('sha256', $time . '.' . $body, $key);
  }

}
