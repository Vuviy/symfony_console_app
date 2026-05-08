<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\ValueObject\FilePath;
use App\Tests\AbstractTestCase;
use InvalidArgumentException;

final class FilePathTest extends AbstractTestCase
{
    public function testConstructorAcceptsValidPath(): void
    {
        $path = new FilePath('/var/backups/dump.sql.gz');

        self::assertSame('/var/backups/dump.sql.gz', $path->getValue());
    }

    public function testConstructorThrowsOnEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must not be empty/i');

        new FilePath('');
    }

    public function testConstructorThrowsOnWhitespaceOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FilePath('   ');
    }

    public function testGetDirectory(): void
    {
        $path = new FilePath('/var/backups/dump.sql.gz');

        self::assertSame('/var/backups', $path->getDirectory());
    }

    public function testGetBasename(): void
    {
        $path = new FilePath('/var/backups/dump.sql.gz');

        self::assertSame('dump.sql.gz', $path->getBasename());
    }

    public function testGetExtension(): void
    {
        $path = new FilePath('/var/backups/dump.sql.gz');

        self::assertSame('gz', $path->getExtension());
    }

    public function testAppendSegment(): void
    {
        $base   = new FilePath('/var/backups');
        $result = $base->append('dump.sql.gz');

        self::assertSame('/var/backups/dump.sql.gz', $result->getValue());
    }

    public function testAppendNormalizesSlashes(): void
    {
        $base   = new FilePath('/var/backups/');
        $result = $base->append('/dump.sql.gz');

        self::assertSame('/var/backups/dump.sql.gz', $result->getValue());
    }

    public function testWithSuffix(): void
    {
        $path   = new FilePath('/var/backups/dump');
        $result = $path->withSuffix('.sql.gz');

        self::assertSame('/var/backups/dump.sql.gz', $result->getValue());
    }

    public function testEquals(): void
    {
        $a = new FilePath('/var/backups/dump.sql.gz');
        $b = new FilePath('/var/backups/dump.sql.gz');
        $c = new FilePath('/var/backups/other.sql.gz');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    public function testToString(): void
    {
        $path = new FilePath('/var/backups/dump.sql.gz');

        self::assertSame('/var/backups/dump.sql.gz', (string) $path);
    }
}