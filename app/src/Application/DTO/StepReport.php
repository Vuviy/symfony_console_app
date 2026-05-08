<?php

declare(strict_types=1);

namespace App\Application\DTO;

/**
 * Outcome of a single deployment step.
 */
final class StepReport
{
    public function __construct(
        public readonly string $stepName,
        public readonly bool   $success,
        public readonly int    $exitCode,
        public readonly string $output,
        public readonly float  $durationSeconds,
    ) {}
}