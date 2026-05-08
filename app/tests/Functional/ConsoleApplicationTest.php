<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Console\ConsoleApplication;
use App\Tests\AbstractTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * ConsoleApplicationTest
 *
 * Smoke-tests that the application boots and commands are reachable.
 */
final class ConsoleApplicationTest extends AbstractTestCase
{
    private ApplicationTester $tester;

    protected function setUp(): void
    {
        $consoleApplication = new ConsoleApplication();
        $symfonyApp         = $consoleApplication->getSymfonyApplication();

        // Required for ApplicationTester to work correctly.
        $symfonyApp->setAutoExit(false);
        $symfonyApp->setCatchExceptions(false);

        $this->tester = new ApplicationTester($symfonyApp);
    }

    public function testApplicationBoots(): void
    {
        $exitCode = $this->tester->run(['command' => 'list']);

        self::assertSame(0, $exitCode, $this->tester->getDisplay());
    }

    public function testGreetCommandIsRegistered(): void
    {
        $exitCode = $this->tester->run(['command' => 'app:greet']);

        self::assertSame(0, $exitCode, $this->tester->getDisplay());
    }

    public function testGreetCommandWithName(): void
    {
        $exitCode = $this->tester->run([
            'command' => 'app:greet',
            'name'    => 'Alice',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Alice', $this->tester->getDisplay());
    }

    public function testGreetCommandWithShoutOption(): void
    {
        $exitCode = $this->tester->run([
            'command' => 'app:greet',
            'name'    => 'Alice',
            '--shout' => true,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('ALICE', $this->tester->getDisplay());
    }
}
