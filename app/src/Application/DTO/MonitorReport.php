<?php

declare(strict_types=1);

namespace App\Application\DTO;

/**
 * Data Transfer Object carrying system health snapshot for display/logging.
 */
final class MonitorReport
{
    public function __construct(
        public readonly string $collectedAt,
        public readonly DiskReport   $disk,
        public readonly MemoryReport $memory,
        public readonly bool   $databaseReachable,
        public readonly string $overallStatus,  // 'ok' | 'warning' | 'critical'
    ) {}
}