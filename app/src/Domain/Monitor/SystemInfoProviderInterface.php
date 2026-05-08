<?php

declare(strict_types=1);

namespace App\Domain\Monitor;

use App\Domain\ValueObject\DatabaseDsn;

/**
 * Contract for reading raw system metrics.
 * Infrastructure layer provides OS-specific implementations.
 */
interface SystemInfoProviderInterface
{
    /**
     * Collect disk metrics for the given mount point (default: '/').
     */
    public function getDiskMetrics(string $mountPoint = '/'): DiskMetrics;

    /**
     * Collect current memory metrics.
     */
    public function getMemoryMetrics(): MemoryMetrics;

    /**
     * Attempt a lightweight connection to the database and return whether it succeeds.
     */
    public function isDatabaseReachable(DatabaseDsn $dsn): bool;
}