<?php

declare(strict_types=1);

namespace Drupal\tracker_monitoring;

/**
 * Reads what Tracker needs to know about the site's packages.
 *
 * It is the same three things Tracker takes from a composer.lock and a
 * composer.json: the installed production packages, what each of them requires,
 * and what the project itself asks for. A site has no composer.lock on the
 * server, so the packages come from vendor/composer/installed.json.
 */
final class PackageReader {

  /**
   * Constructs the reader.
   *
   * @param string $projectRoot
   *   The directory that has composer.json and vendor/.
   */
  public function __construct(
    private readonly string $projectRoot,
  ) {}

  /**
   * Reads the packages.
   *
   * @return array{packages: array<string, string>, requirements: array<string, string[]>, requires: string[]}
   *   Installed production packages (name in lower case to version without a
   *   leading "v"), what each requires of other packages, and the project's
   *   own `require`.
   *
   * @throws \RuntimeException
   *   When a file is missing or is not what Composer writes.
   */
  public function read(): array {
    $installed = $this->json($this->projectRoot . '/vendor/composer/installed.json');
    // Composer 1 wrote a plain list; Composer 2 wraps it and names the dev
    // packages, which are not deployed and are left out.
    $entries = array_is_list($installed) ? $installed : ($installed['packages'] ?? NULL);
    if (!is_array($entries)) {
      throw new \RuntimeException('vendor/composer/installed.json has no "packages" list.');
    }
    $dev = array_map('strtolower', array_filter((array) ($installed['dev-package-names'] ?? []), 'is_string'));

    $packages = [];
    $requirements = [];
    foreach ($entries as $entry) {
      if (!is_array($entry) || !is_string($entry['name'] ?? NULL) || !is_string($entry['version'] ?? NULL)) {
        continue;
      }
      $name = strtolower($entry['name']);
      if (in_array($name, $dev, TRUE)) {
        continue;
      }
      $packages[$name] = ltrim($entry['version'], 'vV');
      $requirements[$name] = self::packageNames(is_array($entry['require'] ?? NULL) ? array_keys($entry['require']) : []);
    }
    if ($packages === []) {
      throw new \RuntimeException('vendor/composer/installed.json has no installed packages.');
    }

    $project = $this->json($this->projectRoot . '/composer.json');
    $require = $project['require'] ?? [];

    return [
      'packages' => $packages,
      'requirements' => $requirements,
      'requires' => self::packageNames(is_array($require) ? array_keys($require) : []),
    ];
  }

  /**
   * Keeps the real packages: "vendor/name", in lower case.
   *
   * PHP and its extensions are not packages.
   *
   * @param array<int|string, mixed> $names
   *   Names as a require section has them.
   *
   * @return string[]
   *   The package names.
   */
  private static function packageNames(array $names): array {
    $packages = [];
    foreach ($names as $name) {
      if (is_string($name) && str_contains($name, '/')) {
        $packages[] = strtolower($name);
      }
    }
    return $packages;
  }

  /**
   * Reads a JSON file into an array.
   *
   * @return array<int|string, mixed>
   *   The decoded file.
   */
  private function json(string $path): array {
    $text = is_readable($path) ? file_get_contents($path) : FALSE;
    if ($text === FALSE) {
      throw new \RuntimeException(sprintf('%s cannot be read.', $path));
    }
    try {
      $data = json_decode($text, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw new \RuntimeException(sprintf('%s is not valid JSON.', $path));
    }
    if (!is_array($data)) {
      throw new \RuntimeException(sprintf('%s is not what Composer writes.', $path));
    }
    return $data;
  }

}
