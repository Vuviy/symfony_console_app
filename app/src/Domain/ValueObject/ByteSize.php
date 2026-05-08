<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Represents a byte-size value (disk space, memory, etc.) as a pure value object.
 */
final class ByteSize
{
    private readonly int $bytes;

    public function __construct(int $bytes)
    {
        if ($bytes < 0) {
            throw new InvalidArgumentException(
                sprintf('ByteSize must not be negative, got %d.', $bytes)
            );
        }

        $this->bytes = $bytes;
    }

    public static function fromBytes(int $bytes): self
    {
        return new self($bytes);
    }

    public static function fromKilobytes(float $kb): self
    {
        return new self((int) round($kb * 1_024));
    }

    public static function fromMegabytes(float $mb): self
    {
        return new self((int) round($mb * 1_024 * 1_024));
    }

    public static function fromGigabytes(float $gb): self
    {
        return new self((int) round($gb * 1_024 * 1_024 * 1_024));
    }

    public function toBytes(): int
    {
        return $this->bytes;
    }

    public function toKilobytes(): float
    {
        return $this->bytes / 1_024;
    }

    public function toMegabytes(): float
    {
        return $this->bytes / 1_024 / 1_024;
    }

    public function toGigabytes(): float
    {
        return $this->bytes / 1_024 / 1_024 / 1_024;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->bytes > $other->bytes;
    }

    public function isLessThan(self $other): bool
    {
        return $this->bytes < $other->bytes;
    }

    public function equals(self $other): bool
    {
        return $this->bytes === $other->bytes;
    }

    public function add(self $other): self
    {
        return new self($this->bytes + $other->bytes);
    }

    /**
     * Human-readable string (e.g. "1.23 GB", "512.00 MB").
     */
    public function toHumanReadable(): string
    {
        return match (true) {
            $this->bytes >= 1_073_741_824 => sprintf('%.2f GB', $this->toGigabytes()),
            $this->bytes >= 1_048_576     => sprintf('%.2f MB', $this->toMegabytes()),
            $this->bytes >= 1_024         => sprintf('%.2f KB', $this->toKilobytes()),
            default                        => sprintf('%d B',   $this->bytes),
        };
    }

    public function __toString(): string
    {
        return $this->toHumanReadable();
    }
}