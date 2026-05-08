<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Represents a percentage in range [0, 100].
 */
final class Percentage
{
    private readonly float $value;

    public function __construct(float $value)
    {
        if ($value < 0.0 || $value > 100.0) {
            throw new InvalidArgumentException(
                sprintf('Percentage must be between 0 and 100, got %.2f.', $value)
            );
        }

        $this->value = $value;
    }

    public static function fromFraction(float $fraction): self
    {
        return new self($fraction * 100.0);
    }

    public static function fromRatio(int|float $used, int|float $total): self
    {
        if ($total <= 0) {
            throw new InvalidArgumentException('Total must be greater than 0.');
        }

        return new self(($used / $total) * 100.0);
    }

    public function getValue(): float
    {
        return $this->value;
    }

    public function isAbove(float $threshold): bool
    {
        return $this->value > $threshold;
    }

    public function isBelow(float $threshold): bool
    {
        return $this->value < $threshold;
    }

    public function isCritical(float $criticalThreshold = 90.0): bool
    {
        return $this->isAbove($criticalThreshold);
    }

    public function isWarning(float $warningThreshold = 75.0): bool
    {
        return $this->isAbove($warningThreshold);
    }

    public function format(int $decimals = 2): string
    {
        return sprintf('%.' . $decimals . 'f%%', $this->value);
    }

    public function __toString(): string
    {
        return $this->format();
    }
}