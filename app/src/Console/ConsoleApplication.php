<?php

declare(strict_types=1);

namespace App\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class ConsoleApplication
{
    private const DEFAULT_NAME    = 'Console Application';
    private const DEFAULT_VERSION = '1.0.0';

    private Application $application;

    public function __construct()
    {
        $name    = $_SERVER['APP_NAME']    ?? self::DEFAULT_NAME;
        $version = $_SERVER['APP_VERSION'] ?? self::DEFAULT_VERSION;

        $this->application = new Application($name, $version);
        $this->application->setCatchExceptions(true);
        $this->application->setAutoExit(false);

        $this->registerCommands();
    }

    /**
     * Run the console application.
     *
     * @return int Exit code (0 = success, non-zero = error)
     */
    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        try {
            return $this->application->run($input, $output);
        } catch (Throwable $throwable) {
            fwrite(STDERR, sprintf('[FATAL] %s%s', $throwable->getMessage(), PHP_EOL));

            return 1;
        }
    }

    /**
     * Expose the inner Symfony Application (e.g. for testing).
     */
    public function getSymfonyApplication(): Application
    {
        return $this->application;
    }

    /**
     * Load and register all commands via config/services.php.
     */
    private function registerCommands(): void
    {
        $register = require dirname(__DIR__, 2) . '/config/services.php';
        $register($this->application);
    }
}
