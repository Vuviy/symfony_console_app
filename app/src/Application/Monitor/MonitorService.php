<?php

declare(strict_types=1);

namespace App\Application\Monitor;

use App\Application\DTO\DiskReport;
use App\Application\DTO\MemoryReport;
use App\Application\DTO\MonitorReport;
use App\Domain\Monitor\SystemInfoProviderInterface;
use App\Domain\Monitor\SystemMetrics;
use App\Domain\ValueObject\DatabaseDsn;
use App\Domain\ValueObject\Timestamp;
use Psr\Log\LoggerInterface;
use Throwable;

final class MonitorService implements MonitorServiceInterface
{
    private const DISK_WARNING_THRESHOLD   = 75.0;
    private const DISK_CRITICAL_THRESHOLD  = 90.0;
    private const MEM_WARNING_THRESHOLD    = 75.0;
    private const MEM_CRITICAL_THRESHOLD   = 90.0;

    public function __construct(
        private readonly SystemInfoProviderInterface $provider,
        private readonly LoggerInterface             $logger,
    ) {}

    public function collect(string $dsnString, string $mountPoint = '/'): MonitorReport
    {
        $this->logger->info('Collecting system metrics.', [
            'mount_point' => $mountPoint,
        ]);

        try {
            $dsn           = DatabaseDsn::fromString($dsnString);
            $disk          = $this->provider->getDiskMetrics($mountPoint);
            $memory        = $this->provider->getMemoryMetrics();
            $dbReachable   = $this->provider->isDatabaseReachable($dsn);
            $collectedAt   = Timestamp::now();

            $metrics = new SystemMetrics($disk, $memory, $dbReachable, $collectedAt);

            $status = $this->resolveStatus($metrics);

            $this->logger->info('Metrics collected.', [
                'disk_usage_pct'  => $disk->getUsedPercentage()->getValue(),
                'mem_usage_pct'   => $memory->getUsedPercentage()->getValue(),
                'db_reachable'    => $dbReachable,
                'status'          => $status,
            ]);

            return $this->buildReport($metrics, $status);

        } catch (Throwable $throwable) {
            $this->logger->error('Failed to collect metrics.', [
                'error' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }
    }

    private function resolveStatus(SystemMetrics $metrics): string
    {
        if ($metrics->hasAnyCritical(self::DISK_CRITICAL_THRESHOLD, self::MEM_CRITICAL_THRESHOLD)) {
            return 'critical';
        }

        if ($metrics->hasAnyWarning(self::DISK_WARNING_THRESHOLD, self::MEM_WARNING_THRESHOLD)) {
            return 'warning';
        }

        return 'ok';
    }

    private function buildReport(SystemMetrics $metrics, string $status): MonitorReport
    {
        $disk   = $metrics->getDisk();
        $memory = $metrics->getMemory();

        $diskReport = new DiskReport(
            mountPoint:  $disk->getMountPoint(),
            total:       $disk->getTotal()->toHumanReadable(),
            used:        $disk->getUsed()->toHumanReadable(),
            free:        $disk->getFree()->toHumanReadable(),
            usedPercent: $disk->getUsedPercentage()->format(),
            isWarning:   $disk->getUsedPercentage()->isWarning(self::DISK_WARNING_THRESHOLD),
            isCritical:  $disk->getUsedPercentage()->isCritical(self::DISK_CRITICAL_THRESHOLD),
        );

        $memReport = new MemoryReport(
            total:       $memory->getTotal()->toHumanReadable(),
            used:        $memory->getUsed()->toHumanReadable(),
            free:        $memory->getFree()->toHumanReadable(),
            usedPercent: $memory->getUsedPercentage()->format(),
            isWarning:   $memory->getUsedPercentage()->isWarning(self::MEM_WARNING_THRESHOLD),
            isCritical:  $memory->getUsedPercentage()->isCritical(self::MEM_CRITICAL_THRESHOLD),
        );

        return new MonitorReport(
            collectedAt:       $metrics->getCollectedAt()->toAtom(),
            disk:              $diskReport,
            memory:            $memReport,
            databaseReachable: $metrics->isDatabaseReachable(),
            overallStatus:     $status,
        );
    }
}