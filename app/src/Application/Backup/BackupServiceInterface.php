<?php

declare(strict_types=1);

namespace App\Application\Backup;

use App\Application\DTO\BackupResult;

interface BackupServiceInterface
{
    /**
     * Execute a full database backup.
     *
     * @param string $dsnString Full DSN URL for the database, e.g. mysql://user:pass@host/db
     * @param string $backupDir Directory where backup files are stored.
     */
    public function run(string $dsnString, string $backupDir): BackupResult;
}