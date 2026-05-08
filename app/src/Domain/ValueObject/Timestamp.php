<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Immutable timestamp value object. Wraps DateTimeImmutable.
 */
final class Timestamp
{
    private readonly DateTimeImmutable $dateTime;

    public function __construct(DateTimeImmutable $dateTime)
    {
        $this->dateTime = $dateTime;
    }

    public static function now(): self
    {
        return new self(new DateTimeImmutable());
    }

    public static function fromString(string $datetime): self
    {
        $dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $datetime);

        if ($dt === false) {
            // Try a more lenient parse
            $dt = new DateTimeImmutable($datetime);
        }

        return new self($dt);
    }

    public static function fromUnixTimestamp(int $unix): self
    {
        $dt = (new DateTimeImmutable())->setTimestamp($unix);

        return new self($dt);
    }

    public function toDateTimeImmutable(): DateTimeImmutable
    {
        return $this->dateTime;
    }

    public function format(string $format): string
    {
        return $this->dateTime->format($format);
    }

    /**
     * Returns a filename-safe representation: YmdHis
     */
    public function toFilenameString(): string
    {
        return $this->dateTime->format('Ymd_His');
    }

    public function toAtom(): string
    {
        return $this->dateTime->format(DateTimeInterface::ATOM);
    }

    public function isBefore(self $other): bool
    {
        return $this->dateTime < $other->dateTime;
    }

    public function isAfter(self $other): bool
    {
        return $this->dateTime > $other->dateTime;
    }

    public function diffInSeconds(self $other): int
    {
        return (int) abs($this->dateTime->getTimestamp() - $other->dateTime->getTimestamp());
    }

    public function diffInDays(self $other): int
    {
        return (int) round($this->diffInSeconds($other) / 86400);
    }

    public function __toString(): string
    {
        return $this->toAtom();
    }
}