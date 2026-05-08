<?php

declare(strict_types=1);

namespace App\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * AbstractCommand
 *
 * Base class for all application commands.
 * Provides shared infrastructure: SymfonyStyle IO helper, environment access,
 * and a structured execute() contract that sub-classes implement.
 */
abstract class AbstractCommand extends Command
{
    protected InputInterface  $input;
    protected OutputInterface $output;
    protected SymfonyStyle    $io;

    // ── Lifecycle ────────────────────────────────────────────────────────────

    /**
     * Symfony calls this before execute(). Use it to validate/transform input.
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->input  = $input;
        $this->output = $output;
        $this->io     = new SymfonyStyle($input, $output);
    }

    /**
     * Symfony calls this after initialize() but before execute().
     * Override to prompt the user for any missing arguments/options.
     */
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        // No-op by default. Override in sub-commands as needed.
    }

    /**
     * Entry point for each command's logic.
     *
     * @return int One of the Command::* exit-code constants
     */
    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->handle();
        } catch (\Throwable $throwable) {
            $this->io->error(sprintf(
                '[%s] %s',
                $throwable::class,
                $throwable->getMessage()
            ));

            if ($output->isVerbose()) {
                $this->io->text($throwable->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    // ── Contract ─────────────────────────────────────────────────────────────

    /**
     * Implement the command's business logic here.
     * Return Command::SUCCESS (0) or Command::FAILURE (1).
     */
    abstract protected function handle(): int;

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Returns the current runtime environment (dev | prod | test).
     */
    protected function environment(): string
    {
        return $_SERVER['APP_ENV'] ?? 'prod';
    }

    /**
     * Returns true when APP_DEBUG is enabled.
     */
    protected function isDebug(): bool
    {
        return filter_var($_SERVER['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
