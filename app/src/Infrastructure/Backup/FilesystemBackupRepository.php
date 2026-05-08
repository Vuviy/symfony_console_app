<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup;

use App\Domain\Backup\BackupFile;
use App\Domain\Backup\BackupRepositoryInterface;
use App\Domain\ValueObject\ByteSize;
use App\Domain\ValueObject\FilePath;
use App\Domain\ValueObject\Timestamp;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Filesystem-backed backup repository.
 *
 * Treats the backup directory as the persistence layer:
 * - findAll()         → scans *.sql.gz files on disk
 * - save()            → no-op (file already written by the dumper)
 * - delete()          → unlinks the file from disk
 * - findOlderThan()   → filters by file mtime
 */
final class FilesystemBackupRepository implements BackupRepositoryInterface
{
    private const BACKUP_GLOB = '*.sql.gz';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function save(BackupFile $backupFile): void
    {
        // File is already written to disk by MysqlDumper.
        // This hook is intentionally a no-op but kept for interface compliance
        // and potential future indexing/metadata persistence.
        $this->logger->debug('BackupRepository: save() called (no-op for filesystem backend).', [
            'path' => (string) $backupFile->getPath(),
        ]);
    }

    /**
     * @return BackupFile[]
     */
    public function findAll(): array
    {
        return $this->scanDirectory();
    }

    /**
     * @return BackupFile[]
     */
    public function findOlderThan(int $retentionDays): array
    {
        $now   = Timestamp::now();
        $files = $this->scanDirectory();

        return array_values(
            array_filter(
                $files,
                static fn(BackupFile $f): bool => $f->isOlderThan($retentionDays, $now),
            )
        );
    }

    public function delete(BackupFile $backupFile): void
    {
        $path = (string) $backupFile->getPath();

        if (!file_exists($path)) {
            $this->logger->warning('BackupRepository: file to delete does not exist.', [
                'path' => $path,
            ]);

            return;
        }

        if (!unlink($path)) {
            throw new RuntimeException(
                sprintf('Failed to delete backup file "%s".', $path)
            );
        }

        $this->logger->debug('BackupRepository: deleted file.', ['path' => $path]);
    }

    public function count(): int
    {
        return count($this->scanDirectory());
    }

    /**
     * Scan a directory for backup files and build BackupFile entities.
     *
     * @return BackupFile[]
     */
    private function scanDirectory(): array
    {
        // The repository is stateless; the directory is resolved at call time
        // from the context provided by the caller (see CleanService / BackupService).
        // We scan all *.sql.gz files in all directories that have been used.
        // For simplicity, directories are tracked via a registered list.
        // If you need multi-directory support, inject an array of paths.
        $files = [];

        foreach ($this->resolveGlobPaths() as $globPath) {
            $matches = glob($globPath);

            if ($matches === false) {
                continue;
            }

            foreach ($matches as $filePath) {
                $mtime = filemtime($filePath);
                $size  = filesize($filePath);

                if ($mtime === false || $size === false) {
                    continue;
                }

                $files[] = new BackupFile(
                    path:      new FilePath($filePath),
                    createdAt: Timestamp::fromUnixTimestamp($mtime),
                    size:      ByteSize::fromBytes($size),
                );
            }
        }

        // Sort oldest first.
        usort($files, static fn(BackupFile $a, BackupFile $b): int =>
            $a->getCreatedAt()->toDateTimeImmutable()
            <=> $b->getCreatedAt()->toDateTimeImmutable()
        );

        return $files;
    }

    /**
     * Returns registered glob patterns. Add directories via addDirectory().
     *
     * @return string[]
     */
    private function resolveGlobPaths(): array
    {
        return $this->directories;
    }

    /** @var string[] */
    private array $directories = [];

    /**
     * Register a backup directory for scanning.
     * Call this before findAll()/findOlderThan()/count().
     */
    public function addDirectory(string $directory): void
    {
        $pattern = rtrim($directory, '/') . '/' . self::BACKUP_GLOB;

        if (!in_array($pattern, $this->directories, true)) {
            $this->directories[] = $pattern;
        }
    }
}