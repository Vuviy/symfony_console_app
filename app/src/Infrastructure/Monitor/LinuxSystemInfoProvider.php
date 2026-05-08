<?php

declare(strict_types=1);

namespace App\Infrastructure\Monitor;

use App\Domain\Monitor\DiskMetrics;
use App\Domain\Monitor\MemoryMetrics;
use App\Domain\Monitor\SystemInfoProviderInterface;
use App\Domain\ValueObject\ByteSize;
use App\Domain\ValueObject\DatabaseDsn;
use App\Domain\ValueObject\Percentage;
use App\Infrastructure\Database\PdoConnectionFactory;
use RuntimeException;

/**
 * Reads disk and memory metrics using native PHP functions + /proc/meminfo.
 * Suitable for Linux environments. Falls back gracefully on macOS.
 */
final class LinuxSystemInfoProvider implements SystemInfoProviderInterface
{
    public function __construct(
        private readonly PdoConnectionFactory $connectionFactory,
    ) {}

    public function getDiskMetrics(string $mountPoint = '/'): DiskMetrics
    {
        $total = disk_total_space($mountPoint);
        $free  = disk_free_space($mountPoint);

        if ($total === false || $free === false) {
            throw new RuntimeException(
                sprintf('Failed to read disk metrics for mount point "%s".', $mountPoint)
            );
        }

        $total = (int) $total;
        $free  = (int) $free;
        $used  = $total - $free;

        return new DiskMetrics(
            mountPoint:     $mountPoint,
            total:          ByteSize::fromBytes($total),
            used:           ByteSize::fromBytes($used),
            free:           ByteSize::fromBytes($free),
            usedPercentage: Percentage::fromRatio($used, $total),
        );
    }

    public function getMemoryMetrics(): MemoryMetrics
    {
        // Try /proc/meminfo first (Linux).
        if (is_readable('/proc/meminfo')) {
            return $this->readProcMeminfo();
        }

        // macOS / BSD fallback: use vm_stat via proc_open.
        return $this->readVmStat();
    }

    public function isDatabaseReachable(DatabaseDsn $dsn): bool
    {
        return $this->connectionFactory->isReachable($dsn);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function readProcMeminfo(): MemoryMetrics
    {
        $content = file_get_contents('/proc/meminfo');

        if ($content === false) {
            throw new RuntimeException('Cannot read /proc/meminfo.');
        }

        $values = [];
        foreach (explode(PHP_EOL, $content) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s+kB/', $line, $matches)) {
                $values[$matches[1]] = (int) $matches[2] * 1024; // kB → bytes
            }
        }

        $required = ['MemTotal', 'MemFree', 'Buffers', 'Cached'];
        foreach ($required as $key) {
            if (!isset($values[$key])) {
                throw new RuntimeException(
                    sprintf('/proc/meminfo is missing expected key "%s".', $key)
                );
            }
        }

        $total = $values['MemTotal'];
        // "Available" memory accounts for buffers/cache which are reclaimable.
        $free  = $values['MemFree'] + $values['Buffers'] + $values['Cached'];
        $used  = $total - $free;

        // Guard against edge cases (e.g. heavily swapped systems).
        $used = max(0, $used);
        $free = max(0, $free);

        return new MemoryMetrics(
            total:          ByteSize::fromBytes($total),
            used:           ByteSize::fromBytes($used),
            free:           ByteSize::fromBytes($free),
            usedPercentage: Percentage::fromRatio($used, $total),
        );
    }

    private function readVmStat(): MemoryMetrics
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process     = proc_open('vm_stat', $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('proc_open(vm_stat) failed.');
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($output === false || $output === '') {
            throw new RuntimeException('vm_stat returned no output.');
        }

        // Parse page size from header: "Mach Virtual Memory Statistics: (page size of 4096 bytes)"
        $pageSize = 4096;
        if (preg_match('/page size of (\d+) bytes/', $output, $m)) {
            $pageSize = (int) $m[1];
        }

        $pages = [];
        foreach (explode(PHP_EOL, $output) as $line) {
            if (preg_match('/^(.+?):\s+(\d+)/', $line, $m)) {
                $pages[trim($m[1])] = (int) $m[2];
            }
        }

        $freePages  = $pages['Pages free']   ?? 0;
        $activePages = $pages['Pages active'] ?? 0;
        $inactivePages = $pages['Pages inactive'] ?? 0;
        $wiredPages = $pages['Pages wired down'] ?? 0;
        $speculativePages = $pages['Pages speculative'] ?? 0;

        $totalPages = $freePages + $activePages + $inactivePages + $wiredPages + $speculativePages;

        $totalBytes = $totalPages * $pageSize;
        $freeBytes  = ($freePages + $speculativePages) * $pageSize;
        $usedBytes  = $totalBytes - $freeBytes;

        return new MemoryMetrics(
            total:          ByteSize::fromBytes($totalBytes),
            used:           ByteSize::fromBytes($usedBytes),
            free:           ByteSize::fromBytes($freeBytes),
            usedPercentage: Percentage::fromRatio($usedBytes, max(1, $totalBytes)),
        );
    }
}