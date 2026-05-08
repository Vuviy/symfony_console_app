<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup;

use App\Domain\Backup\DatabaseDumperInterface;
use App\Domain\ValueObject\DatabaseDsn;
use App\Domain\ValueObject\FilePath;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Runs `mysqldump | gzip` via a shell process to produce a compressed SQL dump.
 *
 * Security: all user-supplied values are escaped with escapeshellarg().
 * The process is spawned via proc_open() — no static exec() calls.
 */
final class MysqlDumper implements DatabaseDumperInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string          $mysqldumpBinary = 'mysqldump',
        private readonly string          $gzipBinary      = 'gzip',
    ) {}

    public function dump(DatabaseDsn $dsn, FilePath $destinationPath): void
    {
        $this->ensureDirectoryExists($destinationPath);

        $command = $this->buildCommand($dsn, $destinationPath);

        $this->logger->debug('Running mysqldump.', [
            'database'    => $dsn->getDatabase(),
            'destination' => (string) $destinationPath,
        ]);

        $this->execute($command, $destinationPath);
    }

    private function buildCommand(DatabaseDsn $dsn, FilePath $destination): string
    {
        // Build the mysqldump part
        $parts = [
            escapeshellcmd($this->mysqldumpBinary),
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
            '--set-gtid-purged=OFF',
            '-h', escapeshellarg($dsn->getHost()),
            '-P', escapeshellarg((string) $dsn->getPort()),
            '-u', escapeshellarg($dsn->getUsername()),
        ];

        // Password is passed via environment variable to avoid shell history exposure.
        // We prepend MYSQL_PWD=... to the command.
        $passwordPrefix = sprintf(
            'MYSQL_PWD=%s ',
            escapeshellarg($dsn->getPassword()),
        );

        $parts[] = escapeshellarg($dsn->getDatabase());

        $mysqldump = implode(' ', $parts);

        // Pipe to gzip and redirect to file.
        return sprintf(
            '%s%s | %s -c > %s',
            $passwordPrefix,
            $mysqldump,
            escapeshellcmd($this->gzipBinary),
            escapeshellarg((string) $destination),
        );
    }

    private function execute(string $command, FilePath $destination): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],   // stdin
            1 => ['pipe', 'w'],   // stdout
            2 => ['pipe', 'w'],   // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => false]);

        if (!is_resource($process)) {
            throw new RuntimeException(
                'proc_open() failed to start the mysqldump process.'
            );
        }

        // Close stdin
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            // Remove partial output file if it exists.
            $destStr = (string) $destination;
            if (file_exists($destStr)) {
                @unlink($destStr);
            }

            $this->logger->error('mysqldump exited with non-zero code.', [
                'exit_code' => $exitCode,
                'stderr'    => $stderr,
            ]);

            throw new RuntimeException(
                sprintf(
                    'mysqldump failed (exit code %d): %s',
                    $exitCode,
                    trim($stderr ?: 'No error output.'),
                )
            );
        }

        if ($stdout !== '' && $stdout !== false) {
            $this->logger->debug('mysqldump stdout.', ['output' => $stdout]);
        }

        $this->logger->debug('mysqldump succeeded.', [
            'destination' => (string) $destination,
        ]);
    }

    private function ensureDirectoryExists(FilePath $path): void
    {
        $dir = $path->getDirectory();

        if ($dir !== '' && !is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException(
                    sprintf('Failed to create backup directory "%s".', $dir)
                );
            }
        }
    }
}