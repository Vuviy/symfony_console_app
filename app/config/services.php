<?php

declare(strict_types=1);

use App\Application\Backup\BackupService;
use App\Application\Clean\CleanService;
use App\Application\Deployment\DeploymentService;
use App\Application\Monitor\MonitorService;
use App\Console\Command\BackupCommand;
use App\Console\Command\CleanCommand;
use App\Console\Command\DeployCommand;
use App\Console\Command\GreetCommand;
use App\Console\Command\MonitorCommand;
use App\Infrastructure\Backup\FilesystemBackupRepository;
use App\Infrastructure\Backup\MysqlDumper;
use App\Infrastructure\Database\PdoConnectionFactory;
use App\Infrastructure\Logging\ConsoleLogger;
use App\Infrastructure\Monitor\LinuxSystemInfoProvider;
use App\Infrastructure\Process\ShellProcessRunner;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Application;

/**
 * Lightweight manual DI container bootstrap.
 *
 * Wires all infrastructure, application services, and commands together.
 * Replace with symfony/dependency-injection or PHP-DI as the project grows.
 */
return static function (Application $application): void {

    // ── Logger ───────────────────────────────────────────────────────────────
    $logger = new ConsoleLogger(
        minimumLevel: $_SERVER['LOG_LEVEL'] ?? LogLevel::INFO,
        useColors:    !isset($_SERVER['NO_COLOR']),
    );

    // ── Infrastructure ───────────────────────────────────────────────────────
    $pdoFactory = new PdoConnectionFactory();

    $processRunner = new ShellProcessRunner(
        logger: $logger,
    );

    $mysqlDumper = new MysqlDumper(
        logger:          $logger,
        mysqldumpBinary: $_SERVER['MYSQLDUMP_BIN'] ?? 'mysqldump',
        gzipBinary:      $_SERVER['GZIP_BIN']      ?? 'gzip',
    );

    $backupRepository = new FilesystemBackupRepository(
        logger: $logger,
    );

    // Register backup directory from env if provided so the repository can
    // scan it for findAll() / findOlderThan() calls.
    if (!empty($_SERVER['BACKUP_DIR'])) {
        $backupRepository->addDirectory($_SERVER['BACKUP_DIR']);
    }

    // ── Application services ─────────────────────────────────────────────────
    $backupService = new BackupService(
        dumper:     $mysqlDumper,
        repository: $backupRepository,
        logger:     $logger,
    );

    $deploymentService = new DeploymentService(
        runner: $processRunner,
        logger: $logger,
    );

    $monitorService = new MonitorService(
        provider: new LinuxSystemInfoProvider(connectionFactory: $pdoFactory),
        logger:   $logger,
    );

    $cleanService = new CleanService(
        repository: $backupRepository,
        logger:     $logger,
    );

    // ── Commands ─────────────────────────────────────────────────────────────
    $application->add(new GreetCommand());

    $application->add(new DeployCommand(
        deploymentService: $deploymentService,
    ));

    $application->add(new BackupCommand(
        backupService: $backupService,
    ));

    $application->add(new MonitorCommand(
        monitorService: $monitorService,
    ));

    $application->add(new CleanCommand(
        cleanService: $cleanService,
        repository:   $backupRepository,
    ));
};