<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\ValueObject\ByteSize;
use App\Tests\AbstractTestCase;
use InvalidArgumentException;

final class ByteSizeTest extends AbstractTestCase
{
    public function testFromBytes(): void
    {
        $size = ByteSize::fromBytes(1024);

        self::assertSame(1024, $size->toBytes());
    }

    public function testFromKilobytes(): void
    {
        $size = ByteSize::fromKilobytes(1.0);

        self::assertSame(1024, $size->toBytes());
    }

    public function testFromMegabytes(): void
    {
        $size = ByteSize::fromMegabytes(1.0);

        self::assertSame(1_048_576, $size->toBytes());
    }

    public function testFromGigabytes(): void
    {
        $size = ByteSize::fromGigabytes(1.0);

        self::assertSame(1_073_741_824, $size->toBytes());
    }

    public function testNegativeBytesThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must not be negative/i');

        ByteSize::fromBytes(-1);
    }

    public function testToHumanReadableBytes(): void
    {
        self::assertSame('512 B', ByteSize::fromBytes(512)->toHumanReadable());
    }

    public function testToHumanReadableKilobytes(): void
    {
        self::assertSame('1.00 KB', ByteSize::fromBytes(1024)->toHumanReadable());
    }

    public function testToHumanReadableMegabytes(): void
    {
        self::assertSame('1.00 MB', ByteSize::fromMegabytes(1)->toHumanReadable());
    }

    public function testToHumanReadableGigabytes(): void
    {
        self::assertSame('1.00 GB', ByteSize::fromGigabytes(1)->toHumanReadable());
    }

    public function testIsGreaterThan(): void
    {
        $large = ByteSize::fromMegabytes(10);
        $small = ByteSize::fromMegabytes(1);

        self::assertTrue($large->isGreaterThan($small));
        self::assertFalse($small->isGreaterThan($large));
    }

    public function testAdd(): void
    {
        $a      = ByteSize::fromBytes(500);
        $b      = ByteSize::fromBytes(524);
        $result = $a->add($b);

        self::assertSame(1024, $result->toBytes());
    }

    public function testEquals(): void
    {
        $a = ByteSize::fromBytes(1024);
        $b = ByteSize::fromBytes(1024);
        $c = ByteSize::fromBytes(2048);

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}