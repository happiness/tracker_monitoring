<?php

declare(strict_types=1);

namespace Drupal\tracker_monitoring;

/**
 * Takes what identifies a visitor out of a log message.
 *
 * The rules are those of shippers/vector.yaml in happiness/tracker-logs and of
 * Tracker's own check (same regular expressions, same order), so a line gets
 * the same message whichever way it reaches the log server.
 * tests/fixtures/log-scrubbing.json holds the cases they are all held to.
 * Tracker scrubs again and makes the signature itself: this is the site's part,
 * so that nothing leaves the site unscrubbed.
 */
final class LogScrubber {

  /**
   * Replaces email addresses, IPv4 addresses and query strings.
   */
  public static function message(string $message): string {
    $message = mb_scrub($message, 'UTF-8');
    $message = (string) preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', '<email>', $message);
    $message = (string) preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}\b/u', '<ip>', $message);
    return (string) preg_replace('/\?\S*/u', '?<query>', $message);
  }

}
