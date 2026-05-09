<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Application\DTO\DiskReport;
use App\Application\DTO\MemoryReport;
use App\Application\DTO\MonitorReport;
use App\Application\Monitor\MonitorServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:monitor',
    description: 'Display a system health report: disk, memory, and database connectivity.',
    aliases: ['monitor'],
)]
final class MonitorCommand extends AbstractCommand
{
    private const DEFAULT_MOUNT_POINT   = '/';
    private const DEFAULT_DSN_ENV       = 'DATABASE_URL';

    // Thresholds mirrored from MonitorService for coloring decisions in the command.
    private const DISK_WARNING_THRESHOLD  = 75.0;
    private const DISK_CRITICAL_THRESHOLD = 90.0;
    private const MEM_WARNING_THRESHOLD   = 75.0;
    private const MEM_CRITICAL_THRESHOLD  = 90.0;

    public function __construct(
        private readonly MonitorServiceInterface $monitorService,
    ) {
        parent::__construct();
    }

    // ── Configuration ────────────────────────────────────────────────────────

    protected function configure(): void
    {
        $this
            ->addOption(
                name:        'dsn',
                shortcut:    null,
                mode:        InputOption::VALUE_REQUIRED,
                description: 'Database DSN URL for connectivity check (mysql://user:pass@host/db).',
            )
            ->addOption(
                name:        'dsn-env',
                shortcut:    null,
                mode:        InputOption::VALUE_REQUIRED,
                description: 'Environment variable that holds the DSN (default: DATABASE_URL).',
                default:     self::DEFAULT_DSN_ENV,
            )
            ->addOption(
                name:        'mount',
                shortcut:    'm',
                mode:        InputOption::VALUE_REQUIRED,
                description: 'Filesystem mount point to check disk usage for.',
                default:     self::DEFAULT_MOUNT_POINT,
            )
            ->addOption(
                name:        'watch',
                shortcut:    'w',
                mode:        InputOption::VALUE_REQUIRED,
                description: 'Repeat the check every N seconds (0 = run once).',
                default:     '0',
            )
            ->addOption(
                name:        'fail-on-warning',
                shortcut:    null,
                mode:        InputOption::VALUE_NONE,
                description: 'Exit with code 1 when any metric reaches warning threshold.',
            )
            ->addOption(
                name:        'fail-on-critical',
                shortcut:    null,
                mode:        InputOption::VALUE_NONE,
                description: 'Exit with code 1 when any metric reaches critical threshold (default behaviour).',
            )
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command collects and displays system health metrics.

                <comment>Basic usage:</comment>
                  <info>php %command.full_name%</info>

                <comment>Provide DSN explicitly:</comment>
                  <info>php %command.full_name% --dsn="mysql://root:pass@127.0.0.1:3306/mydb"</info>

                <comment>Read DSN from a custom environment variable:</comment>
                  <info>php %command.full_name% --dsn-env=MY_DB_DSN</info>

                <comment>Check a specific mount point:</comment>
                  <info>php %command.full_name% --mount=/data</info>

                <comment>Watch mode (refresh every 5 seconds):</comment>
                  <info>php %command.full_name% --watch=5</info>

                <comment>Exit non-zero on any warning (useful in CI / alerting scripts):</comment>
                  <info>php %command.full_name% --fail-on-warning</info>

                <comment>Metrics collected:</comment>
                  • Disk — total / used / free / used-percent for the given mount point
                  • Memory — total / used / free / used-percent (via /proc/meminfo or vm_stat)
                  • Database — reachability via a lightweight PDO ping

                <comment>Status levels:</comment>
                  <fg=green>ok</>       — all metrics below warning threshold
                  <fg=yellow>warning</>  — at least one metric above 75 %
                  <fg=red>critical</> — at least one metric above 90 % or DB unreachable

                Exit codes:
                  <info>0</info> — status ok (or warning when --fail-on-warning is not set)
                  <info>1</info> — critical condition detected (or warning with --fail-on-warning)
                HELP
            );
    }

    // ── Interaction ───────────────────────────────────────────────────────────

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if ($this->resolveDsn() !== null) {
            return;
        }

        $dsn = $this->io->ask(
            question: 'Database DSN for connectivity check (mysql://user:pass@host/db)',
            default:  null,
            validator: static function (?string $value): string {
                $value = trim((string) $value);

                if ($value === '') {
                    throw new \RuntimeException('DSN must not be empty.');
                }

                if (!str_starts_with($value, 'mysql://')) {
                    throw new \RuntimeException('DSN must start with "mysql://".');
                }

                return $value;
            },
        );

        $input->setOption('dsn', $dsn);
    }

    // ── Execution ────────────────────────────────────────────────────────────

    protected function handle(): int
    {
        $dsn        = $this->resolveDsn();
        $mountPoint = (string) ($this->input->getOption('mount') ?? self::DEFAULT_MOUNT_POINT);
        $watchEvery = (int) ($this->input->getOption('watch') ?? 0);

        if ($dsn === null) {
            $this->io->error([
                'No database DSN provided.',
                'Pass --dsn, set DATABASE_URL, or use --dsn-env.',
            ]);

            return Command::FAILURE;
        }

        if ($watchEvery > 0) {
            return $this->runWatchLoop($dsn, $mountPoint, $watchEvery);
        }

        $report = $this->monitorService->collect($dsn, $mountPoint);

        return $this->renderReport($report);
    }

    // ── Private: watch loop ───────────────────────────────────────────────────

    private function runWatchLoop(string $dsn, string $mountPoint, int $intervalSeconds): int
    {
        $this->io->writeln(sprintf(
            '<fg=cyan>Watch mode — refreshing every %d second(s). Press Ctrl+C to stop.</>' . PHP_EOL,
            $intervalSeconds,
        ));

        $lastCode = Command::SUCCESS;

        while (true) {
            // Clear the previous output block by printing a separator.
            $this->output->writeln(
                sprintf(
                    '<fg=gray>── Refresh at %s ──────────────────────────────────────────</>',
                    (new \DateTimeImmutable())->format('H:i:s'),
                )
            );

            $report   = $this->monitorService->collect($dsn, $mountPoint);
            $lastCode = $this->renderReport($report);

            sleep($intervalSeconds);
        }

        // @phpstan-ignore-next-line (unreachable — loop exits via SIGINT)
        return $lastCode;
    }

    // ── Private: rendering ────────────────────────────────────────────────────

    private function renderReport(MonitorReport $report): int
    {
        $this->printHeader($report);
        $this->printDiskTable($report->disk);
        $this->printMemoryTable($report->memory);
        $this->printDatabaseRow($report->databaseReachable);
        $this->printOverallStatus($report->overallStatus);

        return $this->resolveExitCode($report);
    }

    private function printHeader(MonitorReport $report): void
    {
        $this->io->title('🖥  System Health Monitor');

        $this->io->definitionList(
            ['Collected at' => $report->collectedAt],
            ['Mount point'  => $report->disk->mountPoint],
            ['Status'       => $this->colorizeStatus($report->overallStatus)],
        );
    }

    private function printDiskTable(DiskReport $disk): void
    {
        $this->io->section('💽 Disk Usage');

        $usedCell = $this->colorizeMetric(
            value:      $disk->used,
            percent:    $disk->usedPercent,
            isWarning:  $disk->isWarning,
            isCritical: $disk->isCritical,
        );

        $this->io->table(
            headers: ['Metric', 'Value', 'Detail'],
            rows: [
                ['Mount point', sprintf('<fg=white>%s</>', $disk->mountPoint), ''],
                ['Total',       sprintf('<fg=white>%s</>', $disk->total),      ''],
                ['Used',        $usedCell,                                     $this->usageBar($disk->usedPercent, $disk->isWarning, $disk->isCritical)],
                ['Free',        sprintf('<fg=white>%s</>', $disk->free),       ''],
                ['Used %',      $this->colorizePercent($disk->usedPercent, $disk->isWarning, $disk->isCritical), ''],
            ],
        );

        if ($disk->isCritical) {
            $this->io->caution(sprintf('Disk usage is CRITICAL (%s). Free up space immediately.', $disk->usedPercent));
        } elseif ($disk->isWarning) {
            $this->io->warning(sprintf('Disk usage is above warning threshold (%s).', $disk->usedPercent));
        }
    }

    private function printMemoryTable(MemoryReport $memory): void
    {
        $this->io->section('🧠 Memory Usage');

        $usedCell = $this->colorizeMetric(
            value:      $memory->used,
            percent:    $memory->usedPercent,
            isWarning:  $memory->isWarning,
            isCritical: $memory->isCritical,
        );

        $this->io->table(
            headers: ['Metric', 'Value', 'Detail'],
            rows: [
                ['Total',  sprintf('<fg=white>%s</>', $memory->total), ''],
                ['Used',   $usedCell,                                  $this->usageBar($memory->usedPercent, $memory->isWarning, $memory->isCritical)],
                ['Free',   sprintf('<fg=white>%s</>', $memory->free),  ''],
                ['Used %', $this->colorizePercent($memory->usedPercent, $memory->isWarning, $memory->isCritical), ''],
            ],
        );

        if ($memory->isCritical) {
            $this->io->caution(sprintf('Memory usage is CRITICAL (%s).', $memory->usedPercent));
        } elseif ($memory->isWarning) {
            $this->io->warning(sprintf('Memory usage is above warning threshold (%s).', $memory->usedPercent));
        }
    }

    private function printDatabaseRow(bool $reachable): void
    {
        $this->io->section('🗄  Database');

        $statusCell = $reachable
            ? '<fg=green>✔ Reachable</>'
            : '<fg=red>✘ Unreachable</>';

        $this->io->table(
            headers: ['Check', 'Result'],
            rows: [
                ['Connectivity', $statusCell],
            ],
        );

        if (!$reachable) {
            $this->io->error('Database is NOT reachable. Check credentials and network connectivity.');
        }
    }

    private function printOverallStatus(string $status): void
    {
        $this->io->section('📊 Overall Status');

        $colored = $this->colorizeStatus($status);

        match ($status) {
            'critical' => $this->io->error(sprintf('Status: %s — Immediate action required.', strtoupper($status))),
            'warning'  => $this->io->warning(sprintf('Status: %s — Some metrics need attention.', strtoupper($status))),
            default    => $this->io->success(sprintf('Status: %s — All metrics within normal range.', strtoupper($status))),
        };
    }

    // ── Private: DSN resolution ───────────────────────────────────────────────

    private function resolveDsn(): ?string
    {
        // 1. Explicit --dsn option
        $opt = $this->input->getOption('dsn');
        if (is_string($opt) && trim($opt) !== '') {
            return trim($opt);
        }

        // 2. Environment variable (--dsn-env or DATABASE_URL)
        $envKey = (string) ($this->input->getOption('dsn-env') ?? self::DEFAULT_DSN_ENV);
        $envVal = $_SERVER[$envKey] ?? $_ENV[$envKey] ?? getenv($envKey);

        if (is_string($envVal) && trim($envVal) !== '') {
            return trim($envVal);
        }

        return null;
    }

    // ── Private: exit code ────────────────────────────────────────────────────

    private function resolveExitCode(MonitorReport $report): int
    {
        $failOnWarning  = (bool) $this->input->getOption('fail-on-warning');
        $failOnCritical = (bool) $this->input->getOption('fail-on-critical');

        if ($report->overallStatus === 'critical') {
            // Critical always returns failure (unless overridden by the caller).
            return Command::FAILURE;
        }

        if ($report->overallStatus === 'warning' && $failOnWarning) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    // ── Private: formatting helpers ───────────────────────────────────────────

    private function colorizeStatus(string $status): string
    {
        return match ($status) {
            'critical' => '<fg=red;options=bold>CRITICAL</>',
            'warning'  => '<fg=yellow;options=bold>WARNING</>',
            default    => '<fg=green;options=bold>OK</>',
        };
    }

    private function colorizePercent(string $percent, bool $isWarning, bool $isCritical): string
    {
        if ($isCritical) {
            return sprintf('<fg=red;options=bold>%s</>', $percent);
        }

        if ($isWarning) {
            return sprintf('<fg=yellow>%s</>', $percent);
        }

        return sprintf('<fg=green>%s</>', $percent);
    }

    private function colorizeMetric(string $value, string $percent, bool $isWarning, bool $isCritical): string
    {
        if ($isCritical) {
            return sprintf('<fg=red;options=bold>%s</>', $value);
        }

        if ($isWarning) {
            return sprintf('<fg=yellow>%s</>', $value);
        }

        return sprintf('<fg=white>%s</>', $value);
    }

    /**
     * Renders a compact ASCII usage bar, e.g. [████████░░] 82.00%
     */
    private function usageBar(string $percentString, bool $isWarning, bool $isCritical): string
    {
        $value    = (float) rtrim($percentString, '%');
        $filled   = (int) round($value / 10);
        $empty    = 10 - $filled;
        $bar      = str_repeat('█', $filled) . str_repeat('░', $empty);
        $color    = $isCritical ? 'red' : ($isWarning ? 'yellow' : 'green');

        return sprintf('<fg=%s>[%s]</>', $color, $bar);
    }
}