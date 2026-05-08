<?php

declare(strict_types=1);

namespace App\Application\Clean;

use App\Application\DTO\CleanResult;

interface CleanServiceInterface
{
    /**
     * Delete old backups from $backupDir according to the retention policy.
     *
     * @param string $backupDir     Directory containing backup files.
     * @param int    $retentionDays Backups older than this will be deleted.
     * @param int    $minimumKeep   Minimum number of recent backups to always keep.
     */
    public function clean(string $backupDir, int $retentionDays, int $minimumKeep = 3): CleanResult;
}