<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\ValueObject\DatabaseDsn;
use App\Tests\AbstractTestCase;
use InvalidArgumentException;

final class DatabaseDsnTest extends AbstractTestCase
{
    public function testFromStringParsesFullDsn(): void
    {
        $dsn = DatabaseDsn::fromString('mysql://appuser:s3cr3t@db.example.com:3306/mydb');

        self::assertSame('mysql',           $dsn->getDriver());
        self::assertSame('db.example.com',  $dsn->getHost());
        self::assertSame(3306,              $dsn->getPort());
        self::assertSame('mydb',            $dsn->getDatabase());
        self::assertSame('appuser',         $dsn->getUsername());
        self::assertSame('s3cr3t',          $dsn->getPassword());
    }

    public function testFromStringDefaultPort(): void
    {
        $dsn = DatabaseDsn::fromString('mysql://root:pass@localhost/testdb');

        self::assertSame(3306, $dsn->getPort());
    }

    public function testFromStringThrowsOnMissingDatabase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/database name/i');

        DatabaseDsn::fromString('mysql://root:pass@localhost/');
    }

    public function testFromStringThrowsOnInvalidUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid DSN/i');

        DatabaseDsn::fromString('not_a_valid_dsn');
    }

    public function testToPdoDsn(): void
    {
        $dsn    = DatabaseDsn::fromString('mysql://root:pass@127.0.0.1:3306/testdb');
        $pdoDsn = $dsn->toPdoDsn();

        self::assertSame(
            'mysql:host=127.0.0.1;port=3306;dbname=testdb;charset=utf8mb4',
            $pdoDsn,
        );
    }

    public function testToSafeStringHidesPassword(): void
    {
        $dsn  = DatabaseDsn::fromString('mysql://appuser:supersecret@host/db');
        $safe = $dsn->toSafeString();

        self::assertStringNotContainsString('supersecret', $safe);
        self::assertStringContainsString('***', $safe);
        self::assertStringContainsString('appuser', $safe);
    }

    public function testConstructorRejectsEmptyDriver(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DatabaseDsn('', 'localhost', 3306, 'db', 'user', 'pass');
    }

    public function testConstructorRejectsInvalidPort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/port must be between/i');

        new DatabaseDsn('mysql', 'localhost', 99999, 'db', 'user', 'pass');
    }
}