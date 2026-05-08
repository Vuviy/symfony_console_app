<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\ValueObject\Percentage;
use App\Tests\AbstractTestCase;
use InvalidArgumentException;

final class PercentageTest extends AbstractTestCase
{
    public function testConstructorAcceptsValidRange(): void
    {
        $pct = new Percentage(55.5);

        self::assertEqualsWithDelta(55.5, $pct->getValue(), 0.001);
    }

    public function testConstructorRejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Percentage(-1.0);
    }

    public function testConstructorRejectsAbove100(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Percentage(100.1);
    }

    public function testFromFraction(): void
    {
        $pct = Percentage::fromFraction(0.5);

        self::assertEqualsWithDelta(50.0, $pct->getValue(), 0.001);
    }

    public function testFromRatio(): void
    {
        $pct = Percentage::fromRatio(3, 4);

        self::assertEqualsWithDelta(75.0, $pct->getValue(), 0.001);
    }

    public function testFromRatioThrowsOnZeroTotal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/greater than 0/i');

        Percentage::fromRatio(1, 0);
    }

    public function testIsWarning(): void
    {
        self::assertTrue((new Percentage(80.0))->isWarning(75.0));
        self::assertFalse((new Percentage(70.0))->isWarning(75.0));
    }

    public function testIsCritical(): void
    {
        self::assertTrue((new Percentage(92.0))->isCritical(90.0));
        self::assertFalse((new Percentage(88.0))->isCritical(90.0));
    }

    public function testFormat(): void
    {
        $pct = new Percentage(66.666);

        self::assertSame('66.67%', $pct->format(2));
        self::assertSame('66.7%', $pct->format(1));
    }

    public function testToString(): void
    {
        $pct = new Percentage(50.0);

        self::assertSame('50.00%', (string) $pct);
    }
}