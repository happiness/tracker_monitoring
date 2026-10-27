<?php

declare(strict_types=1);

namespace Drupal\tracker_monitoring;

use Drupal\Core\Site\Settings;

/**
 * Makes the package reader for this site.
 */
final class PackageReaderFactory {

  /**
   * Makes the reader.
   *
   * @param string $appRoot
   *   The Drupal root (the web root).
   */
  public static function create(string $appRoot): PackageReader {
    return new PackageReader((string) Settings::get('tracker_monitoring_project_root', dirname($appRoot)));
  }

}
