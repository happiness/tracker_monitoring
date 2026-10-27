<?php

declare(strict_types=1);

namespace Drupal\Tests\tracker_monitoring\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\tracker_monitoring\PackageReader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the packages are read the way Tracker takes them in.
 */
#[Group('tracker_monitoring')]
#[RunTestsInSeparateProcesses]
final class PackageReaderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'tracker_monitoring'];

  /**
   * Production packages only, names in lower case, versions without a "v".
   */
  public function testPackagesAreProductionOnly(): void {
    $read = (new PackageReader(self::fixture()))->read();

    $this->assertSame([
      'drupal/core-recommended' => '11.0.5',
      'drupal/core' => '11.0.5',
      'twig/twig' => '3.10.3',
      'symfony/yaml' => '7.1.1',
      'drupal/admin_toolbar' => '3.5.1',
    ], $read['packages']);
  }

  /**
   * What each package requires: real packages only, in lower case.
   */
  public function testRequirementsLeaveOutPhpAndExtensions(): void {
    $read = (new PackageReader(self::fixture()))->read();

    $this->assertSame(['drupal/core', 'twig/twig'], $read['requirements']['drupal/core-recommended']);
    $this->assertSame(['symfony/yaml'], $read['requirements']['drupal/core']);
    $this->assertSame([], $read['requirements']['symfony/yaml'], 'a package with no require section');
    $this->assertArrayNotHasKey('phpunit/phpunit', $read['requirements']);
  }

  /**
   * The project's own requirements: `require`, not `require-dev`.
   */
  public function testRequiresAreTheProjectsOwn(): void {
    $read = (new PackageReader(self::fixture()))->read();

    $this->assertSame(['drupal/core-recommended', 'drupal/admin_toolbar'], $read['requires']);
  }

  /**
   * A site without the files says what is missing.
   */
  public function testMissingFilesAreSaid(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('installed.json cannot be read');

    (new PackageReader(self::fixture() . '/nowhere'))->read();
  }

  /**
   * Gets the directory of the example site.
   */
  private static function fixture(): string {
    return dirname(__DIR__, 2) . '/fixtures/site';
  }

}
