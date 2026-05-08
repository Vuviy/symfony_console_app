<?php

declare(strict_types=1);

namespace App\Domain\Deployment;

use App\Domain\ValueObject\Timestamp;

/**
 * Represents the outcome of executing a single DeploymentStep.
 */
final class DeploymentStepResult
{
    public function __construct(
        private readonly DeploymentStep $step,
        private readonly bool           $success,
        private readonly string         $output,
        private readonly int            $exitCode,
        private readonly Timestamp      $executedAt,
        private readonly float          $durationSeconds,
    ) {}

    public function getStep(): DeploymentStep    { return $this->step; }
    public function isSuccess(): bool            { return $this->success; }
    public function getOutput(): string          { return $this->output; }
    public function getExitCode(): int           { return $this->exitCode; }
    public function getExecutedAt(): Timestamp   { return $this->executedAt; }
    public function getDurationSeconds(): float  { return $this->durationSeconds; }

    public function __toString(): string
    {
        return sprintf(
            'StepResult{step=%s, success=%s, exit=%d, duration=%.2fs}',
            $this->step->getName(),
            $this->success ? 'true' : 'false',
            $this->exitCode,
            $this->durationSeconds,
        );
    }
}

/**
 * Aggregate result of a full deployment run.
 */
final class DeploymentResult
{
    /** @var DeploymentStepResult[] */
    private array $stepResults = [];

    public function __construct(
        private readonly Timestamp $startedAt,
        private readonly string    $environment,
    ) {}

    public function addStepResult(DeploymentStepResult $result): void
    {
        $this->stepResults[] = $result;
    }

    /** @return DeploymentStepResult[] */
    public function getStepResults(): array
    {
        return $this->stepResults;
    }

    public function getStartedAt(): Timestamp
    {
        return $this->startedAt;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function isSuccess(): bool
    {
        foreach ($this->stepResults as $result) {
            if (!$result->isSuccess() && !$result->getStep()->shouldContinueOnFailure()) {
                return false;
            }
        }

        return true;
    }

    public function getFailedSteps(): array
    {
        return array_filter(
            $this->stepResults,
            static fn(DeploymentStepResult $r): bool => !$r->isSuccess()
        );
    }

    public function getTotalDurationSeconds(): float
    {
        return array_sum(
            array_map(
                static fn(DeploymentStepResult $r): float => $r->getDurationSeconds(),
                $this->stepResults
            )
        );
    }
}