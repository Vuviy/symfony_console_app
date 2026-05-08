<?php

declare(strict_types=1);

use App\Console\Command\GreetCommand;
use Symfony\Component\Console\Application;

/**
 * Returns a configured Symfony Console Application with all commands registered.
 *
 * This file acts as a lightweight service container bootstrap.
 * Replace with a proper DI container (e.g. symfony/dependency-injection)
 * as the application grows.
 */
return static function (Application $application): void {
    // Register commands here.
    // Each command should be instantiated with its dependencies injected explicitly.
    //
    // Example:
    //   $application->add(new GreetCommand());

    $application->add(new GreetCommand());
};
