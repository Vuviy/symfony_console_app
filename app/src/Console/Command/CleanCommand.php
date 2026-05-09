<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Application\Clean\CleanServiceInterface;
use App\Application\DTO\CleanResult;
use App\Infrastructure\Backup\FilesystemBackupRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:clean',
    description: 'Remove old backup files according to a configurable retention policy.',
    aliases: ['clean'],
)]
final class CleanCommand extends AbstractCommand
{
    private const DEFAULT_BACKUP_DIR     = '/var/backups/app';
    private const DEFAULT_RETENTION_DAYS = 30;
    private const DEFAULT_MINIMUM_KEEP   = 3;

    public function __construct(
        private readonly CleanServiceInterface       $cleanService,
        private readonly FilesystemBackupRepository  $repository,
    ) {
        parent::__construct();
    }

    // ── Configuration ────────────────────────────────────────────────────────

    protected function configure(): void
    {
        $this
            ->addOption(
                name:        'backup-dir',
                shortcut:    'o',
                mode:        InputOption::VALUE_REQUIRED,
                description: 'Directory containing backup files to clean.',
                default:     self::DEFAULT_BACKUP_DIR,
            )
            ->addOption(
                name:        'retention-days',
                shortcut:    'r',
                mode:        InputOption::VALUE_REQUIRED,
                description: 'Delete backups older than this many days.',
                default:     (string) self::DEFAULT_RETENTION_DAYS,
            )
            ->addOption(
                name:        'minimum-keep',
                shortcut:    'k',
                mode:        InputOption::VALUE_REQUIRED,
                description: 'Always keep at least this many of the most recent backups regardless of age.',
                default:     (string) self::DEFAULT_MINIMUM_KEEP,
            )
            ->addOption(
                name:        'dry-run',
                shortcut:    'd',
                mode:        InputOption::VALUE_NONE,
                description: 'List files that would be deleted without actually deleting them.',
            )
            ->addOption(
                name:        'force',
                shortcut:    'f',
                mode:        InputOption::VALUE_NONE,
                description: 'Skip the confirmation prompt and delete immediately.',
            )
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command removes old backup files using a retention policy.

                <comment>Basic usage (uses defaults: 30-day retention, keep at least 3):</comment>
                  <info>php %command.full_name%</info>

                <comment>Custom backup directory and retention:</comment>
                  <info>php %command.full_name% --backup-dir=/srv/backups --retention-days=14</info>

                <comment>Always keep the 5 most recent backups:</comment>
                  <info>php %command.full_name% --minimum-keep=5</info>

                <comment>Preview which files would be deleted without deleting them:</comment>
                  <info>php %command.full_name% --dry-run</info>

                <comment>Skip confirmation prompt (use in automated pipelines):</comment>
                  <info>php %command.full_name% --force</info>

                <comment>Retention policy logic:</comment>
                  1. The <info>--minimum-keep</info> most recent backups are always protected.
                  2. Among remaining files, those older than <info>--retention-days</info> are deleted.
                  3. Files not matching either criterion are left untouched.

                Exit codes:
                  <info>0</info> — success (including "nothing to delete")
                  <info>1</info> — deletion failed or was rejected
                HELP
            );
    }

    // ── Interaction ───────────────────────────────────────────────────────────

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $backupDir = (string) ($input->getOption('backup-dir') ?? self::DEFAULT_BACKUP_DIR);

        if (!is_dir($backupDir)) {
            // Let handle() report the error with proper formatting.
            return;
        }

        // If retention-days was not explicitly supplied, offer an interactive prompt.
        // We check for the default value as a proxy for "not supplied".
        $retentionDays = (int) ($input->getOption('retention-days') ?? self::DEFAULT_RETENTION_DAYS);

        if ($retentionDays === self::DEFAULT_RETENTION_DAYS && !$input->getOption('force')) {
            $confirmed = $this->io->confirm(
                question: sprintf(
                    'Use default retention policy? (<fg=yellow>%d days</>, keep at least <fg=yellow>%d</> backups)',
                    self::DEFAULT_RETENTION_DAYS,
                    (int) ($input->getOption('minimum-keep') ?? self::DEFAULT_MINIMUM_KEEP),
                ),
                default: true,
            );

            if (!$confirmed) {
                $days = $this->io->ask(
                    question: 'Retention period in days',
                    default:  (string) self::DEFAULT_RETENTION_DAYS,
                    validator: static function (?string $value): string {
                        $int = (int) $value;
                        if ($int < 1) {
                            throw new \RuntimeException('Retention days must be at least 1.');
                        }

                        return (string) $int;
                    },
                );

                $input->setOption('retention-days', $days);
            }
        }
    }

    // ── Execution ────────────────────────────────────────────────────────────

    protected function handle(): int
    {
        $backupDir     = (string) ($this->input->getOption('backup-dir')     ?? self::DEFAULT_BACKUP_DIR);
        $retentionDays = (int)    ($this->input->getOption('retention-days') ?? self::DEFAULT_RETENTION_DAYS);
        $minimumKeep   = (int)    ($this->input->getOption('minimum-keep')   ?? self::DEFAULT_MINIMUM_KEEP);
        $isDryRun      = (bool)    $this->input->getOption('dry-run');
        $isForced      = (bool)    $this->input->getOption('force');

        if ($retentionDays < 1) {
            $this->io->error('--retention-days must be at least 1.');

            return Command::FAILURE;
        }

        if ($minimumKeep < 0) {
            $this->io->error('--minimum-keep must be 0 or greater.');

            return Command::FAILURE;
        }

        if (!is_dir($backupDir)) {
            $this->io->error(sprintf('Backup directory does not exist: "%s".', $backupDir));

            return Command::FAILURE;
        }

        $this->printHeader($backupDir, $retentionDays, $minimumKeep, $isDryRun);

        // Register the directory so the repository can scan it.
        $this->repository->addDirectory($backupDir);

        if ($isDryRun) {
            return $this->runDryRun($backupDir, $retentionDays, $minimumKeep);
        }

        return $this->runClean($backupDir, $retentionDays, $minimumKeep, $isForced);
    }

    // ── Private: dry-run ─────────────────────────────────────────────────────

    private function runDryRun(string $backupDir, int $retentionDays, int $minimumKeep): int
    {
        $this->io->section('🔍 Dry-run — files that would be deleted:');

        // Use the repository directly to preview what the policy would select.
        $candidates = $this->repository->findOlderThan($retentionDays);

        // Exclude the $minimumKeep most-recent files (mirrors RetentionPolicy logic).
        $all = $this->repository->findAll();
        usort($all, static fn($a, $b) =>
            $b->getCreatedAt()->toDateTimeImmutable() <=> $a->getCreatedAt()->toDateTimeImmutable()
        );
        $protectedPaths = array_map(
            static fn($f): string => (string) $f->getPath(),
            array_slice($all, 0, $minimumKeep),
        );

        $toDelete = array_filter(
            $candidates,
            static fn($f): bool => !in_array((string) $f->getPath(), $protectedPaths, true),
        );

        if (count($toDelete) === 0) {
            $this->io->success('No backups match the deletion criteria — nothing would be removed.');

            return Command::SUCCESS;
        }

        $rows      = [];
        $totalSize = 0;

        foreach ($toDelete as $file) {
            $bytes      = $file->getSize()->toBytes();
            $totalSize += $bytes;

            $rows[] = [
                sprintf('<fg=red>%s</>', (string) $file->getPath()),
                $file->getCreatedAt()->format('Y-m-d H:i:s'),
                $this->formatBytes($bytes),
            ];
        }

        $this->io->table(
            headers: ['File', 'Created At', 'Size'],
            rows:    $rows,
        );

        $this->io->note([
            sprintf('Dry-run: %d file(s) would be deleted.', count($toDelete)),
            sprintf('Total space that would be freed: %s.', $this->formatBytes($totalSize)),
            'Run without --dry-run to apply.',
        ]);

        return Command::SUCCESS;
    }

    // ── Private: real clean ───────────────────────────────────────────────────

    private function runClean(
        string $backupDir,
        int    $retentionDays,
        int    $minimumKeep,
        bool   $isForced,
    ): int {
        // ── Pre-scan: show what will be deleted and ask for confirmation ──────
        $all = $this->repository->findAll();

        if (count($all) === 0) {
            $this->io->success('No backup files found in the directory — nothing to clean.');

            return Command::SUCCESS;
        }

        $this->io->section('📂 Current Backups');
        $this->printBackupTable($all);

        if (!$isForced) {
            $confirmed = $this->io->confirm(
                question: sprintf(
                    'Delete backups older than <fg=yellow>%d days</> while keeping at least <fg=yellow>%d</> file(s)?',
                    $retentionDays,
                    $minimumKeep,
                ),
                default: false,
            );

            if (!$confirmed) {
                $this->io->warning('Clean cancelled by user.');

                return Command::SUCCESS;
            }
        }

        // ── Execute via service ───────────────────────────────────────────────
        $this->io->section('🗑  Deleting old backups…');

        ProgressBar::setFormatDefinition('clean', implode(PHP_EOL, [
            ' [%bar%] %elapsed:6s%',
            ' 🗑  %message%',
            '',
        ]));

        $progress = new ProgressBar($this->output);
        $progress->setFormat('clean');
        $progress->setBarCharacter('<fg=red>■</>');
        $progress->setEmptyBarCharacter('<fg=gray>□</>');
        $progress->setProgressCharacter('<fg=red>►</>');
        $progress->setMessage('Applying retention policy…');
        $progress->start();

        // Tick a few times to show activity during the blocking call.
        for ($i = 0; $i < 3; $i++) {
            $progress->advance();
        }

        try {
            $result = $this->cleanService->clean($backupDir, $retentionDays, $minimumKeep);
        } finally {
            $progress->setMessage('Done.');
            $progress->finish();
            $this->output->writeln('');
            $this->output->writeln('');
        }

        return $this->renderResult($result);
    }

    // ── Private: render result ────────────────────────────────────────────────

    private function renderResult(CleanResult $result): int
    {
        if (!$result->success) {
            $this->io->error(['❌ Clean operation failed.', $result->message]);

            return Command::FAILURE;
        }

        if ($result->deletedCount === 0) {
            $this->io->success('✅ ' . $result->message);

            return Command::SUCCESS;
        }

        // Show which files were deleted.
        $this->io->section('🗑  Deleted Files');

        $rows = array_map(
            static fn(string $path): array => [sprintf('<fg=red>%s</>', $path)],
            $result->deletedPaths,
        );

        $this->io->table(headers: ['Deleted Path'], rows: $rows);

        $this->io->success(sprintf(
            '✅ Deleted %d backup file(s). Freed %s.',
            $result->deletedCount,
            $this->formatBytes($result->freedBytes),
        ));

        return Command::SUCCESS;
    }

    // ── Private: UI helpers ───────────────────────────────────────────────────

    private function printHeader(
        string $backupDir,
        int    $retentionDays,
        int    $minimumKeep,
        bool   $isDryRun,
    ): void {
        $mode = $isDryRun ? ' <fg=yellow>[DRY-RUN]</>' : '';

        $this->io->title(sprintf('🧹 Backup Clean%s', $mode));

        $this->io->definitionList(
            ['Backup directory' => $backupDir],
            ['Retention days'   => sprintf('%d days', $retentionDays)],
            ['Minimum keep'     => sprintf('%d file(s)', $minimumKeep)],
            ['Date'             => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            ['Mode'             => $isDryRun ? 'dry-run' : 'live'],
        );
    }

    /**
     * @param \App\Domain\Backup\BackupFile[] $files
     */
    private function printBackupTable(array $files): void
    {
        $rows = [];

        // Sort newest first for display.
        usort($files, static fn($a, $b) =>
            $b->getCreatedAt()->toDateTimeImmutable() <=> $a->getCreatedAt()->toDateTimeImmutable()
        );

        foreach ($files as $i => $file) {
            $rows[] = [
                sprintf('<fg=cyan>%d</>', $i + 1),
                sprintf('<fg=white>%s</>', $file->getPath()->getBasename()),
                $file->getCreatedAt()->format('Y-m-d H:i:s'),
                $this->formatBytes($file->getSize()->toBytes()),
                $file->getPath()->getDirectory(),
            ];
        }

        $this->io->table(
            headers: ['#', 'Filename', 'Created At', 'Size', 'Directory'],
            rows:    $rows,
        );
    }

    private function formatBytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_073_741_824 => sprintf('%.2f GB', $bytes / 1_073_741_824),
            $bytes >= 1_048_576     => sprintf('%.2f MB', $bytes / 1_048_576),
            $bytes >= 1_024         => sprintf('%.2f KB', $bytes / 1_024),
            default                  => sprintf('%d B',   $bytes),
        };
    }
}