<?php

declare(strict_types=1);

namespace App\Application\Deployment;

use App\Application\DTO\DeploymentReport;
use App\Application\DTO\DeploymentRequest;
use App\Application\DTO\StepReport;
use App\Domain\Deployment\DeploymentResult;
use App\Domain\Deployment\DeploymentStep;
use App\Domain\Deployment\DeploymentStepResult;
use App\Domain\Deployment\ProcessRunnerInterface;
use App\Domain\ValueObject\Timestamp;
use Psr\Log\LoggerInterface;
use Throwable;

final class DeploymentService implements DeploymentServiceInterface
{
    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly LoggerInterface        $logger,
    ) {}

    public function deploy(DeploymentRequest $request): DeploymentReport
    {
        $startedAt = Timestamp::now();

        $this->logger->info('Deployment started.', [
            'environment' => $request->environment,
            'steps'       => count($request->steps),
            'started_at'  => $startedAt->toAtom(),
        ]);

        $result = new DeploymentResult($startedAt, $request->environment);
        $aborted = false;

        foreach ($request->steps as $stepConfig) {
            if ($aborted) {
                $this->logger->warning('Skipping step due to prior failure.', [
                    'step' => $stepConfig['name'] ?? 'unknown',
                ]);
                continue;
            }

            $step = $this->buildStep($stepConfig);
            $stepResult = $this->executeStep($step);
            $result->addStepResult($stepResult);

            if (!$stepResult->isSuccess() && !$step->shouldContinueOnFailure()) {
                $this->logger->error('Deployment aborted after step failure.', [
                    'step'     => $step->getName(),
                    'exit_code' => $stepResult->getExitCode(),
                ]);
                $aborted = true;
            }
        }

        $success = $result->isSuccess();

        $this->logger->info('Deployment finished.', [
            'environment'       => $request->environment,
            'success'           => $success,
            'total_duration_s'  => $result->getTotalDurationSeconds(),
            'failed_steps'      => count($result->getFailedSteps()),
        ]);

        return $this->buildReport($result);
    }

    /**
     * @param array{name: string, command: string, continueOnFailure?: bool, timeoutSeconds?: int} $config
     */
    private function buildStep(array $config): DeploymentStep
    {
        return new DeploymentStep(
            name:              $config['name'],
            command:           $config['command'],
            continueOnFailure: (bool) ($config['continueOnFailure'] ?? false),
            timeoutSeconds:    (int)  ($config['timeoutSeconds']    ?? 300),
        );
    }

    private function executeStep(DeploymentStep $step): DeploymentStepResult
    {
        $this->logger->info('Executing step.', [
            'step'    => $step->getName(),
            'command' => $step->getCommand(),
        ]);

        $executedAt = Timestamp::now();
        $startTime  = microtime(true);

        try {
            $processResult = $this->runner->run(
                $step->getCommand(),
                $step->getTimeoutSeconds(),
            );

            $duration = microtime(true) - $startTime;

            $this->logger->info('Step completed.', [
                'step'       => $step->getName(),
                'exit_code'  => $processResult->getExitCode(),
                'duration_s' => round($duration, 4),
                'success'    => $processResult->isSuccess(),
            ]);

            return new DeploymentStepResult(
                step:            $step,
                success:         $processResult->isSuccess(),
                output:          $processResult->getCombinedOutput(),
                exitCode:        $processResult->getExitCode(),
                executedAt:      $executedAt,
                durationSeconds: $duration,
            );

        } catch (Throwable $throwable) {
            $duration = microtime(true) - $startTime;

            $this->logger->error('Step threw exception.', [
                'step'  => $step->getName(),
                'error' => $throwable->getMessage(),
            ]);

            return new DeploymentStepResult(
                step:            $step,
                success:         false,
                output:          $throwable->getMessage(),
                exitCode:        -1,
                executedAt:      $executedAt,
                durationSeconds: $duration,
            );
        }
    }

    private function buildReport(DeploymentResult $result): DeploymentReport
    {
        $stepReports = array_map(
            static fn(DeploymentStepResult $r): StepReport => new StepReport(
                stepName:        $r->getStep()->getName(),
                success:         $r->isSuccess(),
                exitCode:        $r->getExitCode(),
                output:          $r->getOutput(),
                durationSeconds: $r->getDurationSeconds(),
            ),
            $result->getStepResults(),
        );

        $failedCount = count($result->getFailedSteps());
        $totalSteps  = count($result->getStepResults());

        $summary = $result->isSuccess()
            ? sprintf('Deployment succeeded. %d/%d steps passed.', $totalSteps, $totalSteps)
            : sprintf('Deployment failed. %d/%d steps failed.', $failedCount, $totalSteps);

        return new DeploymentReport(
            success:              $result->isSuccess(),
            environment:          $result->getEnvironment(),
            startedAt:            $result->getStartedAt()->toAtom(),
            totalDurationSeconds: $result->getTotalDurationSeconds(),
            stepReports:          $stepReports,
            summary:              $summary,
        );
    }
}