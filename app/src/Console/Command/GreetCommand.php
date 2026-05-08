<?php

declare(strict_types=1);

namespace App\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * GreetCommand
 *
 * Skeleton example command. Replace or delete once real commands are added.
 * Business logic belongs in an Application\UseCase or Domain\Service class —
 * this command is a thin CLI adapter only.
 */
#[AsCommand(
    name: 'app:greet',
    description: 'Example skeleton command — greets a user by name.',
    aliases: ['greet'],
)]
final class GreetCommand extends AbstractCommand
{
    // ── Configuration ────────────────────────────────────────────────────────

    protected function configure(): void
    {
        $this
            ->addArgument(
                name: 'name',
                mode: InputArgument::OPTIONAL,
                description: 'The name of the person to greet.',
                default: 'World',
            )
            ->addOption(
                name: 'shout',
                shortcut: 's',
                mode: InputOption::VALUE_NONE,
                description: 'Output the greeting in uppercase.',
            )
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command greets a user:

                  <info>php %command.full_name%</info>
                  <info>php %command.full_name% Alice</info>
                  <info>php %command.full_name% Alice --shout</info>

                This is a skeleton command. Replace it with real application logic.
                HELP
            );
    }

    // ── Execution ────────────────────────────────────────────────────────────

    protected function handle(): int
    {
        // TODO: inject and call an Application\UseCase here.

        /** @var string $name */
        $name = $this->input->getArgument('name');

        $greeting = sprintf('Hello, %s!', $name);

        if ($this->input->getOption('shout')) {
            $greeting = strtoupper($greeting);
        }

        $this->io->success($greeting);

        return Command::SUCCESS;
    }
}
