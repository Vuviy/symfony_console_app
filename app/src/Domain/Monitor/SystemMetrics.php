<?php

declare(strict_types=1);

namespace App\Domain\Monitor;

use App\Domain\ValueObject\ByteSize;
use App\Domain\ValueObject\Percentage;
use App\Domain\ValueObject\Timestamp;

/**
 * Snapshot of system health metrics at a point in time.
 */
final class SystemMetrics
{
    public function __construct(
        private readonly DiskMetrics   $disk,
        private readonly MemoryMetrics $memory,
        private readonly bool          $databaseReachable,
        private readonly Timestamp     $collectedAt,
    ) {}

    public function getDisk(): DiskMetrics
    {
        return $this->disk;
    }

    public function getMemory(): MemoryMetrics
    {
        return $this->memory;
    }

    public function isDatabaseReachable(): bool
    {
        return $this->databaseReachable;
    }

    public function getCollectedAt(): Timestamp
    {
        return $this->collectedAt;
    }

    public function hasAnyWarning(
        float $diskWarningThreshold   = 75.0,
        float $memoryWarningThreshold = 75.0,
    ): bool {
        return $this->disk->getUsedPercentage()->isWarning($diskWarningThreshold)
            || $this->memory->getUsedPercentage()->isWarning($memoryWarningThreshold)
            || !$this->databaseReachable;
    }

    public function hasAnyCritical(
        float $diskCriticalThreshold   = 90.0,
        float $memoryCriticalThreshold = 90.0,
    ): bool {
        return $this->disk->getUsedPercentage()->isCritical($diskCriticalThreshold)
            || $this->memory->getUsedPercentage()->isCritical($memoryCriticalThreshold)
            || !$this->databaseReachable;
    }
}

/**
 * Disk usage metrics for a single mount point.
 */
final class DiskMetrics
{
    public function __construct(
        private readonly string     $mountPoint,
        private readonly ByteSize   $total,
        private readonly ByteSize   $used,
        private readonly ByteSize   $free,
        private readonly Percentage $usedPercentage,
    ) {}

    public function getMountPoint(): string      { return $this->mountPoint; }
    public function getTotal(): ByteSize         { return $this->total; }
    public function getUsed(): ByteSize          { return $this->used; }
    public function getFree(): ByteSize          { return $this->free; }
    public function getUsedPercentage(): Percentage { return $this->usedPercentage; }
}

/**
 * Memory usage metrics.
 */
final class MemoryMetrics
{
    public function __construct(
        private readonly ByteSize   $total,
        private readonly ByteSize   $used,
        private readonly ByteSize   $free,
        private readonly Percentage $usedPercentage,
    ) {}

    public function getTotal(): ByteSize            { return $this->total; }
    public function getUsed(): ByteSize             { return $this->used; }
    public function getFree(): ByteSize             { return $this->free; }
    public function getUsedPercentage(): Percentage { return $this->usedPercentage; }
}