<?php

declare(strict_types=1);

namespace App\Domain\Deployment;

/**
 * Contract for executing system processes.
 * Decouples domain/application from exec/shell_exec/proc_open.
 */
interface ProcessRunnerInterface
{
    /**
     * Run $command and return the result.
     *
     * @param int $timeoutSeconds Maximum time to allow the process to run.
     */
    public function run(string $command, int $timeoutSeconds = 300): ProcessRunResult;
}

/**
 * Result of a process execution.
 */
final class ProcessRunResult
{
    public function __construct(
        private readonly int    $exitCode,
        private readonly string $output,
        private readonly string $errorOutput,
    ) {}

    public function getExitCode(): int    { return $this->exitCode; }
    public function getOutput(): string   { return $this->output; }
    public function getErrorOutput(): string { return $this->errorOutput; }
    public function isSuccess(): bool     { return $this->exitCode === 0; }

    public function getCombinedOutput(): string
    {
        $parts = array_filter([$this->output, $this->errorOutput]);

        return implode(PHP_EOL, $parts);
    }
}