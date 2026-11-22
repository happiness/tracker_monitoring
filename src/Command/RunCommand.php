<?php

declare(strict_types=1);

namespace Drupal\tracker_monitoring\Command;

use Drupal\tracker_monitoring\JobReporter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

/**
 * Runs a command and tells Tracker how it went.
 *
 * Run from the system's crontab in place of the command itself:
 * `dr tracker-monitoring:run queue-run -- vendor/bin/drush queue:run reindex`.
 * Tracker is told when the run starts and when it ends, with the exit code, the
 * seconds it took and, for a failed run, the end of its output. The command's
 * output goes on to the console and its exit code is this command's, so the
 * crontab line behaves as before. If Tracker cannot be reached, or the module
 * is not set up, a line on stderr says so and the command runs all the same.
 */
#[AsCommand(
  name: 'tracker-monitoring:run',
  description: 'Runs a command and tells Tracker when it starts and how it ended.',
)]
final class RunCommand {

  public function __construct(
    private readonly JobReporter $reporter,
  ) {}

  /**
   * Runs the command, reports it and returns its exit code.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   The console.
   * @param string $job
   *   The job's name in Tracker.
   * @param string[] $commandline
   *   The command and its arguments, started without a shell.
   */
  public function __invoke(
    SymfonyStyle $io,
    #[Argument("The job's name in Tracker: 1 to 100 lowercase letters, digits, - or _.")]
    string $job,
    #[Argument('The command to run and its arguments, after "--".')]
    array $commandline = [],
  ): int {
    if ($commandline === []) {
      $io->getErrorStyle()->error('No command given: tracker-monitoring:run <job> -- <command> [arguments...]');
      return Command::INVALID;
    }

    $problem = $this->reporter->problem($job);
    if ($problem !== NULL) {
      $io->getErrorStyle()->writeln(sprintf('tracker-monitoring:run: %s The job runs without reporting.', $problem));
    }
    elseif (($failure = $this->reporter->start($job)) !== NULL) {
      $io->getErrorStyle()->writeln(sprintf('tracker-monitoring:run: %s', $failure));
    }

    $started = hrtime(TRUE);
    $tail = '';
    $process = new Process($commandline, timeout: NULL);
    try {
      $process->run(function (string $type, string $buffer) use ($io, &$tail): void {
        $io->write($buffer, FALSE, OutputInterface::OUTPUT_RAW);
        $tail = substr($tail . $buffer, -JobReporter::MAX_OUTPUT);
      });
    }
    catch (ProcessSignaledException) {
      // A job that a signal ended (the kernel's out-of-memory killer, a stop)
      // is the very thing Tracker should hear of, not an exception.
    }
    $code = $process->hasBeenSignaled() ? 128 + $process->getTermSignal() : (int) $process->getExitCode();
    $code = min(255, $code);

    if ($problem === NULL) {
      $failure = $this->reporter->finish($job, $code, intdiv(hrtime(TRUE) - $started, 1_000_000_000), $tail);
      if ($failure !== NULL) {
        $io->getErrorStyle()->writeln(sprintf('tracker-monitoring:run: %s', $failure));
      }
    }
    return $code;
  }

}
