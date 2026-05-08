<?php

declare(strict_types=1);

namespace App\Infrastructure\Process;

use App\Domain\Deployment\ProcessRunnerInterface;
use App\Domain\Deployment\ProcessRunResult;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Executes shell commands via proc_open().
 * No static exec() / shell_exec() calls.
 */
final class ShellProcessRunner implements ProcessRunnerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function run(string $command, int $timeoutSeconds = 300): ProcessRunResult
    {
        $this->logger->debug('ShellProcessRunner: executing command.', [
            'command'         => $command,
            'timeout_seconds' => $timeoutSeconds,
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException(
                sprintf('proc_open() failed to start command: %s', $command)
            );
        }

        // Close stdin immediately — commands don't need interactive input.
        fclose($pipes[0]);

        // Apply timeout to stdout/stderr reads.
        stream_set_timeout($pipes[1], $timeoutSeconds);
        stream_set_timeout($pipes[2], $timeoutSeconds);

        $stdout = $this->readStream($pipes[1]);
        $stderr = $this->readStream($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $this->logger->debug('ShellProcessRunner: command finished.', [
            'exit_code' => $exitCode,
            'stdout_len' => strlen($stdout),
            'stderr_len' => strlen($stderr),
        ]);

        if ($exitCode === -1) {
            throw new RuntimeException(
                sprintf('proc_close() returned -1 for command: %s', $command)
            );
        }

        return new ProcessRunResult(
            exitCode:    $exitCode,
            output:      $stdout,
            errorOutput: $stderr,
        );
    }

    private function readStream(mixed $stream): string
    {
        $content = stream_get_contents($stream);

        return $content !== false ? $content : '';
    }
}