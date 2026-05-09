<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Application\Deployment\DeploymentServiceInterface;
use App\Application\DTO\DeploymentReport;
use App\Application\DTO\DeploymentRequest;
use App\Application\DTO\StepReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:deploy',
    description: 'Run a deployment pipeline defined by a JSON steps file or inline steps.',
    aliases: ['deploy'],
)]
final class DeployCommand extends AbstractCommand
{
    // ── Built-in step presets (used when no --steps-file is provided) ────────
    private const PRESET_STEPS = [
        'default' => [
            ['name' => 'Maintenance ON',   'command' => 'echo "maintenance on"',  'continueOnFailure' => false, 'timeoutSeconds' => 30],
            ['name' => 'Pull latest code', 'command' => 'echo "git pull"',         'continueOnFailure' => false, 'timeoutSeconds' => 120],
            ['name' => 'Install deps',     'command' => 'echo "composer install"', 'continueOnFailure' => false, 'timeoutSeconds' => 300],
            ['name' => 'Run migrations',   'command' => 'echo "migrate"',          'continueOnFailure' => false, 'timeoutSeconds' => 120],
            ['name' => 'Clear cache',      'command' => 'echo "cache:clear"',      'continueOnFailure' => true,  'timeoutSeconds' => 60],
            ['name' => 'Maintenance OFF',  'command' => 'echo "maintenance off"',  'continueOnFailure' => true,  'timeoutSeconds' => 30],
        ],
    ];

    public function __construct(
        private readonly DeploymentServiceInterface $deploymentService,
    ) {
        parent::__construct();
    }

    // ── Configuration ────────────────────────────────────────────────────────

    protected function configure(): void
    {
        $this
            ->addArgument(
                name:        'environment',
                mode:        InputArgument::OPTIONAL,
                description: 'Target deployment environment (e.g. staging, production).',
            )
            ->addOption(
                name:        'steps-file',
                shortcut:    'f',
                mode:        InputOption::VALUE_REQUIRED,
                description: 'Path to a JSON file defining deployment steps.',
            )
            ->addOption(
                name:        'preset',
                shortcut:    'p',
                mode:        InputOption::VALUE_REQUIRED,
                description: 'Use a built-in step preset. Available: default.',
                default:     'default',
            )
            ->addOption(
                name:        'dry-run',
                shortcut:    'd',
                mode:        InputOption::VALUE_NONE,
                description: 'Print steps that would run without executing them.',
            )
            ->addOption(
                name:        'no-interaction-confirm',
                mode:        InputOption::VALUE_NONE,
                description: 'Skip the production confirmation prompt (use in CI pipelines).',
            )
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command runs a structured deployment pipeline.

                <comment>Basic usage:</comment>
                  <info>php %command.full_name% staging</info>
                  <info>php %command.full_name% production</info>

                <comment>Using a custom steps file (JSON):</comment>
                  <info>php %command.full_name% staging --steps-file=deploy/steps.json</info>

                  The JSON file must be an array of step objects:
                  <comment>[
                    {"name": "Pull code",  "command": "git pull origin main"},
                    {"name": "Run tests",  "command": "composer test", "continueOnFailure": true},
                    {"name" : "Migrate",   "command": "php bin/console d:m:m", "timeoutSeconds": 120}
                  ]</comment>

                <comment>Dry-run mode:</comment>
                  <info>php %command.full_name% staging --dry-run</info>

                <comment>Skip production safety prompt (CI):</comment>
                  <info>php %command.full_name% production --no-interaction-confirm</info>

                Exit codes:
                  <info>0</info> — all steps passed
                  <info>1</info> — one or more steps failed (and did not have continueOnFailure)
                HELP
            );
    }

    // ── Interaction (prompts for missing input) ───────────────────────────────

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if (!$input->getArgument('environment')) {
            $environment = $this->io->ask(
                question: 'Target environment',
                default:  'staging',
                validator: static function (?string $value): string {
                    $value = trim((string) $value);
                    if ($value === '') {
                        throw new \RuntimeException('Environment must not be empty.');
                    }

                    return $value;
                },
            );

            $input->setArgument('environment', $environment);
        }
    }

    // ── Execution ────────────────────────────────────────────────────────────

    protected function handle(): int
    {
        /** @var string $environment */
        $environment = $this->input->getArgument('environment');
        $isDryRun    = (bool) $this->input->getOption('dry-run');

        $this->printHeader($environment, $isDryRun);

        // Safety gate for production
        if (!$this->confirmProductionDeploy($environment)) {
            $this->io->warning('Deployment cancelled.');

            return Command::SUCCESS;
        }

        $steps = $this->resolveSteps();

        if (count($steps) === 0) {
            $this->io->error('No deployment steps found. Provide --steps-file or use a --preset.');

            return Command::FAILURE;
        }

        if ($isDryRun) {
            return $this->runDryRun($steps, $environment);
        }

        return $this->runDeployment($steps, $environment);
    }

    // ── Private: step resolution ─────────────────────────────────────────────

    /**
     * @return array<array{name: string, command: string, continueOnFailure?: bool, timeoutSeconds?: int}>
     */
    private function resolveSteps(): array
    {
        $stepsFile = $this->input->getOption('steps-file');

        if ($stepsFile !== null) {
            return $this->loadStepsFromFile((string) $stepsFile);
        }

        $preset = (string) ($this->input->getOption('preset') ?? 'default');

        return self::PRESET_STEPS[$preset] ?? [];
    }

    /**
     * @return array<array{name: string, command: string, continueOnFailure?: bool, timeoutSeconds?: int}>
     */
    private function loadStepsFromFile(string $path): array
    {
        if (!file_exists($path)) {
            throw new \RuntimeException(sprintf('Steps file not found: "%s".', $path));
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException(sprintf('Cannot read steps file: "%s".', $path));
        }

        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Steps file must contain a JSON array.');
        }

        foreach ($decoded as $i => $step) {
            if (empty($step['name']) || empty($step['command'])) {
                throw new \RuntimeException(
                    sprintf('Step at index %d is missing required "name" or "command" key.', $i)
                );
            }
        }

        return $decoded;
    }

    // ── Private: dry-run ─────────────────────────────────────────────────────

    /**
     * @param array<array{name: string, command: string, continueOnFailure?: bool, timeoutSeconds?: int}> $steps
     */
    private function runDryRun(array $steps, string $environment): int
    {
        $this->io->section('🔍 Dry-run — steps that would execute:');

        $rows = [];
        foreach ($steps as $i => $step) {
            $rows[] = [
                sprintf('<fg=cyan>%d</>', $i + 1),
                sprintf('<fg=white;options=bold>%s</>', $step['name']),
                sprintf('<fg=yellow>%s</>', $step['command']),
                ($step['continueOnFailure'] ?? false) ? '<fg=green>yes</>' : '<fg=red>no</>',
                sprintf('%ds', $step['timeoutSeconds'] ?? 300),
            ];
        }

        $this->io->table(
            headers: ['#', 'Step', 'Command', 'Continue on fail', 'Timeout'],
            rows:    $rows,
        );

        $this->io->note(sprintf(
            'Dry-run complete. %d step(s) would run in environment "%s".',
            count($steps),
            $environment,
        ));

        return Command::SUCCESS;
    }

    // ── Private: real deployment ──────────────────────────────────────────────

    /**
     * @param array<array{name: string, command: string, continueOnFailure?: bool, timeoutSeconds?: int}> $steps
     */
    private function runDeployment(array $steps, string $environment): int
    {
        $totalSteps = count($steps);

        $this->io->section(sprintf('🚀 Deploying to <fg=cyan;options=bold>%s</> (%d step(s))', $environment, $totalSteps));

        // ── Progress bar setup ───────────────────────────────────────────────
        ProgressBar::setFormatDefinition('deploy', implode(PHP_EOL, [
            ' %current%/%max% [%bar%] %percent:3s%%',
            ' ⏱  Elapsed: %elapsed:6s%   ETA: %estimated:-6s%',
            ' 📦 Step: %message%',
            '',
        ]));

        $progressBar = new ProgressBar($this->output, $totalSteps);
        $progressBar->setFormat('deploy');
        $progressBar->setBarCharacter('<fg=green>━</>');
        $progressBar->setEmptyBarCharacter('<fg=gray>─</>');
        $progressBar->setProgressCharacter('<fg=green>▶</>');
        $progressBar->setMessage('Starting…');
        $progressBar->start();

        // Advance bar once per step as names arrive — we do this by passing
        // the full request to the service and then rendering results after.
        // The service is synchronous, so we use a two-pass approach:
        //   pass 1 → run all steps via service (with per-step progress faking)
        //   pass 2 → render results table
        //
        // To give real per-step feedback we wrap each step individually.
        $stepResults = [];
        $aborted     = false;

        foreach ($steps as $step) {
            if ($aborted) {
                $progressBar->setMessage(sprintf('<fg=gray>Skipped: %s</>', $step['name']));
                $progressBar->advance();
                continue;
            }

            $progressBar->setMessage(sprintf('<fg=yellow>Running: %s</>', htmlspecialchars($step['name'], ENT_QUOTES)));
            $progressBar->display();

            $singleRequest = new DeploymentRequest(
                environment: $environment,
                steps:       [$step],
            );

            $report = $this->deploymentService->deploy($singleRequest);

            /** @var StepReport $stepReport */
            $stepReport    = $report->stepReports[0];
            $stepResults[] = $stepReport;

            $progressBar->setMessage(
                $stepReport->success
                    ? sprintf('<fg=green>✔ Done: %s</>', $step['name'])
                    : sprintf('<fg=red>✘ Failed: %s</>', $step['name'])
            );
            $progressBar->advance();

            if (!$stepReport->success && !($step['continueOnFailure'] ?? false)) {
                $aborted = true;
            }
        }

        $progressBar->finish();
        $this->output->writeln('');
        $this->output->writeln('');

        // ── Results table ────────────────────────────────────────────────────
        return $this->renderResults($stepResults, $environment);
    }

    /**
     * @param StepReport[] $stepResults
     */
    private function renderResults(array $stepResults, string $environment): int
    {
        $this->io->section('📋 Deployment Summary');

        $rows          = [];
        $overallSuccess = true;

        foreach ($stepResults as $result) {
            if (!$result->success) {
                $overallSuccess = false;
            }

            $status = $result->success
                ? '<fg=green>✔ PASS</>'
                : '<fg=red>✘ FAIL</>';

            $rows[] = [
                $status,
                sprintf('<fg=white>%s</>', $result->stepName),
                sprintf('<fg=gray>%d</>', $result->exitCode),
                sprintf('<fg=gray>%.2fs</>', $result->durationSeconds),
                $result->success ? '' : sprintf('<fg=red>%s</>', $this->truncate($result->output, 60)),
            ];
        }

        $this->io->table(
            headers: ['Status', 'Step', 'Exit', 'Duration', 'Error'],
            rows:    $rows,
        );

        if ($overallSuccess) {
            $this->io->success(sprintf(
                '✅ Deployment to "%s" completed successfully (%d/%d steps passed).',
                $environment,
                count($stepResults),
                count($stepResults),
            ));

            return Command::SUCCESS;
        }

        $failed = array_filter($stepResults, static fn(StepReport $r): bool => !$r->success);

        $this->io->error(sprintf(
            '❌ Deployment to "%s" FAILED. %d step(s) did not pass.',
            $environment,
            count($failed),
        ));

        if ($this->output->isVerbose()) {
            foreach ($failed as $r) {
                $this->io->section(sprintf('Output for failed step: %s', $r->stepName));
                $this->io->text($r->output ?: '(no output)');
            }
        }

        return Command::FAILURE;
    }

    // ── Private: UI helpers ───────────────────────────────────────────────────

    private function printHeader(string $environment, bool $isDryRun): void
    {
        $mode = $isDryRun ? ' <fg=yellow>[DRY-RUN]</>' : '';

        $this->io->title(sprintf(
            'Deployment Pipeline%s — env: <fg=cyan;options=bold>%s</>',
            $mode,
            $environment,
        ));

        $this->io->definitionList(
            ['Environment' => $environment],
            ['Date'        => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            ['Mode'        => $isDryRun ? 'dry-run' : 'live'],
        );
    }

    private function confirmProductionDeploy(string $environment): bool
    {
        if (strtolower($environment) !== 'production') {
            return true;
        }

        if ($this->input->getOption('no-interaction-confirm')) {
            return true;
        }

        $this->io->caution([
            '⚠️  You are deploying to PRODUCTION.',
            'This will affect live users.',
        ]);

        return $this->io->confirm(
            question: 'Are you sure you want to continue?',
            default:  false,
        );
    }

    private function truncate(string $text, int $maxLength): string
    {
        $text = trim(str_replace(["\n", "\r"], ' ', $text));

        if (strlen($text) <= $maxLength) {
            return $text;
        }

        return substr($text, 0, $maxLength - 3) . '…';
    }
}