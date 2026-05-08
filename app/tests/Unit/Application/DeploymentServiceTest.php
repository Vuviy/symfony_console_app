<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\Deployment\DeploymentService;
use App\Application\DTO\DeploymentRequest;
use App\Domain\Deployment\ProcessRunnerInterface;
use App\Domain\Deployment\ProcessRunResult;
use App\Tests\AbstractTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

final class DeploymentServiceTest extends AbstractTestCase
{
    private ProcessRunnerInterface&MockObject $runner;
    private DeploymentService                 $service;

    protected function setUp(): void
    {
        $this->runner  = $this->createMock(ProcessRunnerInterface::class);
        $this->service = new DeploymentService($this->runner, new NullLogger());
    }

    public function testSuccessfulDeploymentAllStepsPass(): void
    {
        $this->runner
            ->expects(self::exactly(2))
            ->method('run')
            ->willReturn(new ProcessRunResult(0, 'ok', ''));

        $request = new DeploymentRequest(
            environment: 'staging',
            steps: [
                ['name' => 'Build', 'command' => 'make build'],
                ['name' => 'Test',  'command' => 'make test'],
            ],
        );

        $report = $this->service->deploy($request);

        self::assertTrue($report->success);
        self::assertSame('staging', $report->environment);
        self::assertCount(2, $report->stepReports);
        self::assertTrue($report->stepReports[0]->success);
        self::assertTrue($report->stepReports[1]->success);
    }

    public function testDeploymentFailsWhenStepFails(): void
    {
        $this->runner
            ->expects(self::once())
            ->method('run')
            ->willReturn(new ProcessRunResult(1, '', 'Build error'));

        $request = new DeploymentRequest(
            environment: 'production',
            steps: [
                ['name' => 'Build', 'command' => 'make build'],
                ['name' => 'Deploy', 'command' => 'make deploy'],
            ],
        );

        $report = $this->service->deploy($request);

        self::assertFalse($report->success);
        // Second step should be skipped after first fails without continueOnFailure.
        self::assertCount(1, $report->stepReports);
        self::assertFalse($report->stepReports[0]->success);
        self::assertSame(1, $report->stepReports[0]->exitCode);
    }

    public function testContinueOnFailureRunsNextStep(): void
    {
        $this->runner
            ->expects(self::exactly(2))
            ->method('run')
            ->willReturnOnConsecutiveCalls(
                new ProcessRunResult(1, '', 'step 1 failed'),
                new ProcessRunResult(0, 'step 2 ok', ''),
            );

        $request = new DeploymentRequest(
            environment: 'dev',
            steps: [
                ['name' => 'Flaky', 'command' => 'flaky-cmd', 'continueOnFailure' => true],
                ['name' => 'Safe',  'command' => 'safe-cmd'],
            ],
        );

        $report = $this->service->deploy($request);

        self::assertCount(2, $report->stepReports);
        self::assertFalse($report->stepReports[0]->success);
        self::assertTrue($report->stepReports[1]->success);
    }

    public function testReportContainsSummary(): void
    {
        $this->runner
            ->method('run')
            ->willReturn(new ProcessRunResult(0, 'done', ''));

        $request = new DeploymentRequest(
            environment: 'dev',
            steps: [['name' => 'Step', 'command' => 'echo done']],
        );

        $report = $this->service->deploy($request);

        self::assertNotEmpty($report->summary);
    }

    public function testEmptyStepsSucceeds(): void
    {
        $this->runner->expects(self::never())->method('run');

        $request = new DeploymentRequest(environment: 'dev', steps: []);
        $report  = $this->service->deploy($request);

        self::assertTrue($report->success);
        self::assertCount(0, $report->stepReports);
    }
}