<?php

declare(strict_types=1);

namespace Drupal\tracker_monitoring;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Where Tracker is.
 *
 * The address is configuration (tracker_monitoring.settings:endpoint) with no
 * default: the module is public, and which Tracker a site reports to is for
 * the site to say.
 */
final class Tracker {

  /**
   * Where reports are posted.
   */
  public const REPORT_PATH = '/hooks/monitoring';

  /**
   * Gets the address of Tracker, without a trailing slash, or '' if not set.
   */
  public static function endpoint(ConfigFactoryInterface $config_factory): string {
    $endpoint = $config_factory->get('tracker_monitoring.settings')->get('endpoint');
    return is_string($endpoint) ? rtrim(trim($endpoint), '/') : '';
  }

  /**
   * Gets the address reports are posted to, or '' if the address is not set.
   */
  public static function reportUrl(ConfigFactoryInterface $config_factory): string {
    $endpoint = self::endpoint($config_factory);
    return $endpoint === '' ? '' : $endpoint . self::REPORT_PATH;
  }

}
