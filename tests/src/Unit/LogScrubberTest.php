<?php

declare(strict_types=1);

namespace Drupal\Tests\tracker_monitoring\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\tracker_monitoring\LogScrubber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that a message is scrubbed the way Tracker and the shipper scrub it.
 */
#[Group('tracker_monitoring')]
final class LogScrubberTest extends UnitTestCase {

  /**
   * Gets the cases shared with Tracker and tracker-logs.
   *
   * @return iterable<string, array{string, string}>
   *   The input and the message it must give.
   */
  public static function cases(): iterable {
    $cases = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/log-scrubbing.json'), TRUE, 8, JSON_THROW_ON_ERROR);
    foreach ($cases as $case) {
      yield $case['name'] => [$case['input'], $case['message']];
    }
  }

  /**
   * A message gives the same text as it does in Tracker's own check.
   */
  #[DataProvider('cases')]
  public function testMessageIsScrubbedLikeTheShipperDoesIt(string $input, string $message): void {
    $this->assertSame($message, LogScrubber::message($input));
  }

  /**
   * Invalid UTF-8 does not empty the message or let an address through.
   */
  public function testInvalidUtf8IsCleanedAndStillScrubbed(): void {
    $message = LogScrubber::message("Broken \xC3 byte for a@b.se");

    $this->assertStringContainsString('<email>', $message);
    $this->assertStringNotContainsString('a@b.se', $message);
  }

}
