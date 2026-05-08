<?php

declare(strict_types=1);

namespace App\Application\DTO;

/**
 * Carries the outcome of a completed deployment run.
 */
final class DeploymentReport
{
    /**
     * @param StepReport[] $stepReports
     */
    public function __construct(
        public readonly bool   $success,
        public readonly string $environment,
        public readonly string $startedAt,
        public readonly float  $totalDurationSeconds,
        public readonly array  $stepReports,
        public readonly string $summary,
    ) {}
}