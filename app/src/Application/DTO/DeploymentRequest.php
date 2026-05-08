<?php

declare(strict_types=1);

namespace App\Application\DTO;

/**
 * Carries configuration needed to start a deployment.
 */
final class DeploymentRequest
{
    /**
     * @param array<array{name: string, command: string, continueOnFailure?: bool, timeoutSeconds?: int}> $steps
     */
    public function __construct(
        public readonly string $environment,
        public readonly array  $steps,
    ) {}
}