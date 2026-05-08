<?php

declare(strict_types=1);

namespace App\Domain\Deployment;

use InvalidArgumentException;

/**
 * Represents a single step in a deployment pipeline.
 */
final class DeploymentStep
{
    public function __construct(
        private readonly string $name,
        private readonly string $command,
        private readonly bool   $continueOnFailure = false,
        private readonly int    $timeoutSeconds    = 300,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Deployment step name must not be empty.');
        }

        if (trim($command) === '') {
            throw new InvalidArgumentException('Deployment step command must not be empty.');
        }

        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException('Timeout must be at least 1 second.');
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function shouldContinueOnFailure(): bool
    {
        return $this->continueOnFailure;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function __toString(): string
    {
        return sprintf('DeploymentStep{name=%s}', $this->name);
    }
}