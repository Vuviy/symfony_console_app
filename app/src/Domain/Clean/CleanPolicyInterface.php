<?php

declare(strict_types=1);

namespace App\Domain\Clean;

use App\Domain\Backup\BackupFile;

/**
 * Defines a strategy for deciding which backup files should be deleted.
 */
interface CleanPolicyInterface
{
    /**
     * Given a full list of backup files, return the subset that should be deleted.
     *
     * @param BackupFile[] $files
     * @return BackupFile[]
     */
    public function resolve(array $files): array;
}