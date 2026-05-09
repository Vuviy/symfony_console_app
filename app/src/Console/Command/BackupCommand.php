<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Application\Backup\BackupServiceInterface;
use App\Application\DTO\BackupResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:backup',
    description: 'Create a compressed MySQL dump and store it in the backup directory.',
    aliases: ['backup'],
)]
final class BackupCommand extends AbstractCommand
{
    private const DEFAULT_BACKUP_DIR = '/var/backups/app';

    public function __construct(
        private readonly BackupServiceInterface $backupService,
    ) {
        parent::__construct();
    }

    // ── Configuration ────────────────────────────────────────────────────────

    protected function configure(): void
    {
        $this
            ->addArgument(
                name:        'dsn',
                mode:        InputArgument::OPTIONAL,
                description: 'Database DSN URL (mysql://user:pass@host:port/database).',
            )
            ->addOption(
                name:        'backup-dir',
                shortcut:    'o',
                mode:        InputOption::VALUE_REQUIRED,
                description: 'Directory where backup files will be stored.',
                default:     self::DEFAULT_BACKUP_DIR,
            )
            ->addOption(
                name:        'dsn-env',
                mode:        InputOption::VALUE_REQUIRED,
                description: 'Read the DSN from this environment variable instead of the argument.',
                default:     'DATABASE_URL',
            )
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command creates a compressed MySQL dump.

                <comment>Provide DSN as argument:</comment>
                  <info>php %command.full_name% "mysql://root:pass@127.0.0.1:3306/mydb"</info>

                <comment>Provide DSN via environment variable (default: DATABASE_URL):</comment>
                  <info>DATABASE_URL="mysql://root:pass@127.0.0.1:3306/mydb" php %command.full_name%</info>

                <comment>Specify a custom output directory:</comment>
                  <info>php %command.full_name% "mysql://..." --backup-dir=/srv/backups</info>

                <comment>Read DSN from a custom environment variable:</comment>
                  <info>php %command.full_name% --dsn-env=MY_DB_DSN</info>

                <comment>Output file format:</comment>
                  <info>{database}_{YYYYmmdd_HHmmss}.sql.gz</info>

                The command exits with code <info>0</info> on success and <info>1</info> on failure.
                HELP
            );
    }

    // ── Interaction ───────────────────────────────────────────────────────────

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        // DSN resolution priority:
        //   1. Argument passed on CLI
        //   2. Environment variable (--dsn-env or DATABASE_URL)
        //   3. Interactive prompt
        if ($this->resolveDsn() !== null) {
            return;
        }

        $dsn = $this->io->ask(
            question: 'Database DSN (mysql://user:pass@host:port/database)',
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

        $input->setArgument('dsn', $dsn);
    }

    // ── Execution ────────────────────────────────────────────────────────────

    protected function handle(): int
    {
        $dsn       = $this->resolveDsn();
        $backupDir = (string) ($this->input->getOption('backup-dir') ?? self::DEFAULT_BACKUP_DIR);

        if ($dsn === null) {
            $this->io->error([
                'No database DSN provided.',
                'Pass it as argument, set the DATABASE_URL env var, or use --dsn-env.',
            ]);

            return Command::FAILURE;
        }

        $this->printHeader($dsn, $backupDir);

        return $this->runBackup($dsn, $backupDir);
    }

    // ── Private: DSN resolution ───────────────────────────────────────────────

    private function resolveDsn(): ?string
    {
        // 1. CLI argument
        $arg = $this->input->getArgument('dsn');
        if (is_string($arg) && trim($arg) !== '') {
            return trim($arg);
        }

        // 2. Environment variable
        $envKey = (string) ($this->input->getOption('dsn-env') ?? 'DATABASE_URL');
        $envVal = $_SERVER[$envKey] ?? $_ENV[$envKey] ?? getenv($envKey);

        if (is_string($envVal) && trim($envVal) !== '') {
            return trim($envVal);
        }

        return null;
    }

    // ── Private: backup execution ─────────────────────────────────────────────

    private function runBackup(string $dsn, string $backupDir): int
    {
        // ── Spinner / progress indication ────────────────────────────────────
        // The backup is a single blocking operation (mysqldump + gzip).
        // We use an indeterminate progress bar to signal activity.
        ProgressBar::setFormatDefinition('backup', implode(PHP_EOL, [
            ' [%bar%] %elapsed:6s%',
            ' 💾 %message%',
            '',
        ]));

        $progress = new ProgressBar($this->output);
        $progress->setFormat('backup');
        $progress->setBarCharacter('<fg=green>■</>');
        $progress->setEmptyBarCharacter('<fg=gray>□</>');
        $progress->setProgressCharacter('<fg=green>►</>');
        $progress->setMessage('Connecting to database and starting dump…');
        $progress->start();

        // Tick to show activity before the blocking call.
        for ($i = 0; $i < 5; $i++) {
            $progress->advance();
        }

        try {
            $result = $this->backupService->run($dsn, $backupDir);
        } finally {
            $progress->finish();
            $this->output->writeln('');
            $this->output->writeln('');
        }

        return $this->renderResult($result, $backupDir);
    }

    private function renderResult(BackupResult $result, string $backupDir): int
    {
        if ($result->success) {
            $this->io->success('✅ Backup completed successfully.');

            $this->io->definitionList(
                ['📁 File'      => $result->backupPath],
                ['📦 Size'      => $this->formatBytes($result->fileSizeBytes)],
                ['🕐 Started'   => $result->startedAt],
                ['🕑 Finished'  => $result->finishedAt],
                ['📂 Directory' => $backupDir],
            );

            if ($this->output->isVerbose()) {
                $this->io->note($result->message);
            }

            return Command::SUCCESS;
        }

        $this->io->error([
            '❌ Backup FAILED.',
            $result->message,
        ]);

        $this->io->definitionList(
            ['🕐 Started'  => $result->startedAt],
            ['📂 Directory' => $backupDir],
        );

        $this->io->note([
            'Troubleshooting tips:',
            '  • Verify the DSN credentials are correct.',
            '  • Check that the backup directory is writable.',
            '  • Ensure mysqldump and gzip are installed and on $PATH.',
            '  • Run with -v or -vv for more detail.',
        ]);

        return Command::FAILURE;
    }

    // ── Private: UI helpers ───────────────────────────────────────────────────

    private function printHeader(string $dsn, string $backupDir): void
    {
        $this->io->title('💾 Database Backup');

        // Redact password from DSN for safe display.
        $safeDsn = preg_replace(
            pattern:     '/(:\/\/)([^:]+):([^@]+)@/',
            replacement: '$1$2:***@',
            subject:     $dsn,
        ) ?? $dsn;

        $this->io->definitionList(
            ['DSN'        => $safeDsn],
            ['Backup dir' => $backupDir],
            ['Date'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
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