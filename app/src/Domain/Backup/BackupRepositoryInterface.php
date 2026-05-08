<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Domain\ValueObject\FilePath;

/**
 * Defines the contract for storing and querying backup files.
 * Infrastructure layer implements this.
 */
interface BackupRepositoryInterface
{
    /**
     * Persist/register a backup file in the repository.
     */
    public function save(BackupFile $backupFile): void;

    /**
     * Return all backup files sorted by creation date, oldest first.
     *
     * @return BackupFile[]
     */
    public function findAll(): array;

    /**
     * Return all backup files older than $retentionDays days.
     *
     * @return BackupFile[]
     */
    public function findOlderThan(int $retentionDays): array;

    /**
     * Remove a backup file from persistent storage and the repository.
     */
    public function delete(BackupFile $backupFile): void;

    /**
     * Return total number of stored backups.
     */
    public function count(): int;
}