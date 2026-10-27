<?php

declare(strict_types=1);

namespace Drupal\Tests\tracker_monitoring\Kernel;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\tracker_monitoring\Config\SyslogFormatOverride;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the syslog format override follows the module's setting.
 */
#[Group('tracker_monitoring')]
#[RunTestsInSeparateProcesses]
final class SyslogFormatOverrideTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'syslog', 'tracker_monitoring'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'syslog', 'tracker_monitoring']);
  }

  /**
   * The override is on by default and sets the format Vector parses.
   */
  public function testFormatIsSetByDefault(): void {
    $syslog = $this->effectiveConfig('syslog.settings');

    $this->assertSame('!base_url|!type|!severity|!message', SyslogFormatOverride::FORMAT);
    $this->assertSame(SyslogFormatOverride::FORMAT, $syslog->get('format'));
  }

  /**
   * The override changes the format only: identity and facility are the site's.
   */
  public function testOnlyTheFormatIsOverridden(): void {
    $this->config('syslog.settings')->setData(['identity' => 'other', 'facility' => 184, 'format' => 'stored'])->save();

    $syslog = $this->effectiveConfig('syslog.settings');
    $this->assertSame(SyslogFormatOverride::FORMAT, $syslog->get('format'));
    $this->assertSame('other', $syslog->get('identity'));
    $this->assertSame(184, $syslog->get('facility'));
  }

  /**
   * The stored configuration is not changed by the override.
   */
  public function testStoredFormatIsUntouched(): void {
    $stored = $this->container->get('config.storage')->read('syslog.settings')['format'];

    $this->assertSame(SyslogFormatOverride::FORMAT, $this->effectiveConfig('syslog.settings')->get('format'));
    $this->assertNotSame(SyslogFormatOverride::FORMAT, $stored);
    $this->assertSame($stored, $this->effectiveConfig('syslog.settings')->getOriginal('format', FALSE));
  }

  /**
   * With the setting off, the site's own format is used.
   */
  public function testSettingOffLeavesTheSitesFormat(): void {
    $this->config('tracker_monitoring.settings')->set('set_syslog_format', FALSE)->save();

    $syslog = $this->effectiveConfig('syslog.settings');
    $this->assertNotSame(SyslogFormatOverride::FORMAT, $syslog->get('format'));
    $this->assertSame($syslog->getOriginal('format', FALSE), $syslog->get('format'));
  }

  /**
   * Config as the site reads it, with overrides. ::config() has none.
   */
  private function effectiveConfig(string $name): ImmutableConfig {
    return $this->container->get('config.factory')->get($name);
  }

}
