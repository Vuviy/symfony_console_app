<?php

declare(strict_types=1);

namespace App\Application\Backup;

use App\Application\DTO\BackupResult;
use App\Domain\Backup\BackupFile;
use App\Domain\Backup\BackupRepositoryInterface;
use App\Domain\Backup\DatabaseDumperInterface;
use App\Domain\ValueObject\ByteSize;
use App\Domain\ValueObject\DatabaseDsn;
use App\Domain\ValueObject\FilePath;
use App\Domain\ValueObject\Timestamp;
use Psr\Log\LoggerInterface;
use Throwable;

final class BackupService implements BackupServiceInterface
{
    public function __construct(
        private readonly DatabaseDumperInterface    $dumper,
        private readonly BackupRepositoryInterface  $repository,
        private readonly LoggerInterface            $logger,
    ) {}

    public function run(string $dsnString, string $backupDir): BackupResult
    {
        $startedAt = Timestamp::now();

        $this->logger->info('Backup started.', [
            'started_at' => $startedAt->toAtom(),
            'backup_dir' => $backupDir,
        ]);

        try {
            $dsn  = DatabaseDsn::fromString($dsnString);
            $path = $this->buildDestinationPath($backupDir, $dsn->getDatabase(), $startedAt);

            $this->logger->info('Dumping database.', [
                'database'    => $dsn->getDatabase(),
                'destination' => (string) $path,
            ]);

            $this->dumper->dump($dsn, $path);

            $fileSizeBytes = $this->resolveFileSize($path);
            $finishedAt    = Timestamp::now();

            $backupFile = new BackupFile(
                path:      $path,
                createdAt: $startedAt,
                size:      ByteSize::fromBytes($fileSizeBytes),
            );

            $this->repository->save($backupFile);

            $this->logger->info('Backup finished.', [
                'path'          => (string) $path,
                'size_bytes'    => $fileSizeBytes,
                'finished_at'   => $finishedAt->toAtom(),
            ]);

            return BackupResult::success(
                backupPath:    (string) $path,
                fileSizeBytes: $fileSizeBytes,
                startedAt:     $startedAt->toAtom(),
                finishedAt:    $finishedAt->toAtom(),
            );

        } catch (Throwable $throwable) {
            $this->logger->error('Backup failed.', [
                'error'      => $throwable->getMessage(),
                'started_at' => $startedAt->toAtom(),
            ]);

            return BackupResult::failure(
                reason:    $throwable->getMessage(),
                startedAt: $startedAt->toAtom(),
            );
        }
    }

    private function buildDestinationPath(
        string    $backupDir,
        string    $database,
        Timestamp $timestamp,
    ): FilePath {
        $filename = sprintf(
            '%s_%s.sql.gz',
            preg_replace('/[^a-zA-Z0-9_-]/', '_', $database),
            $timestamp->toFilenameString(),
        );

        return (new FilePath($backupDir))->append($filename);
    }

    private function resolveFileSize(FilePath $path): int
    {
        $size = @filesize((string) $path);

        return $size !== false ? $size : 0;
    }
}