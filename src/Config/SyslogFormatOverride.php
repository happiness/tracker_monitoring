<?php

declare(strict_types=1);

namespace Drupal\tracker_monitoring\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Gives the syslog module the line format that tracker-logs parses.
 *
 * This is an override, not stored configuration: it applies only while the
 * syslog module's settings are read, so there is no dependency on syslog, and
 * nothing for a config import to undo.
 */
final class SyslogFormatOverride implements ConfigFactoryOverrideInterface {

  /**
   * The format Vector's parse step in tracker-logs expects.
   */
  public const FORMAT = '!base_url|!type|!severity|!message';

  public function __construct(
    private readonly StorageInterface $storage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names): array {
    if (!in_array('syslog.settings', $names, TRUE) || !self::isEnabled($this->storage)) {
      return [];
    }
    return ['syslog.settings' => ['format' => self::FORMAT]];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix(): string {
    return 'tracker_monitoring_syslog_format';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name): CacheableMetadata {
    return (new CacheableMetadata())->addCacheTags(['config:tracker_monitoring.settings']);
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION): null {
    return NULL;
  }

  /**
   * Whether the setting is on. It reads storage, not the config factory, which
   * this override is part of.
   */
  private static function isEnabled(StorageInterface $storage): bool {
    $settings = $storage->read('tracker_monitoring.settings');
    return $settings === FALSE || ($settings['set_syslog_format'] ?? TRUE);
  }

}
