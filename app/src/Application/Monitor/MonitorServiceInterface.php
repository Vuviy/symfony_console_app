<?php

declare(strict_types=1);

namespace App\Application\Monitor;

use App\Application\DTO\MonitorReport;

interface MonitorServiceInterface
{
    /**
     * Collect and return a system health report.
     *
     * @param string $dsnString  DSN for database connectivity check.
     * @param string $mountPoint Filesystem mount point to inspect (default: '/').
     */
    public function collect(string $dsnString, string $mountPoint = '/'): MonitorReport;
}