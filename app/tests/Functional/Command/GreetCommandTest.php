<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Console\Command\GreetCommand;
use App\Tests\AbstractTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * GreetCommandTest
 *
 * Functional tests for GreetCommand.
 * Each test runs the command in isolation via CommandTester.
 */
final class GreetCommandTest extends AbstractTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $application = new Application();
        $application->add(new GreetCommand());

        $command             = $application->find('app:greet');
        $this->commandTester = new CommandTester($command);
    }

    public function testDefaultGreeting(): void
    {
        $exitCode = $this->commandTester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('World', $this->commandTester->getDisplay());
    }

    public function testGreetingWithName(): void
    {
        $exitCode = $this->commandTester->execute(['name' => 'Bob']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Bob', $this->commandTester->getDisplay());
    }

    public function testShoutOption(): void
    {
        $exitCode = $this->commandTester->execute([
            'name'    => 'Alice',
            '--shout' => true,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('HELLO, ALICE!', $this->commandTester->getDisplay());
    }

    public function testCommandHasCorrectName(): void
    {
        self::assertSame('app:greet', (new GreetCommand())->getName());
    }

    public function testCommandHasDescription(): void
    {
        $description = (new GreetCommand())->getDescription();

        self::assertNotEmpty($description);
    }
}
