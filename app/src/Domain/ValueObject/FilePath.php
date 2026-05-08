<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Represents a validated, immutable filesystem path.
 */
final class FilePath
{
    private readonly string $value;

    public function __construct(string $path)
    {
        $trimmed = trim($path);

        if ($trimmed === '') {
            throw new InvalidArgumentException('File path must not be empty.');
        }

        $this->value = $trimmed;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getDirectory(): string
    {
        return dirname($this->value);
    }

    public function getBasename(): string
    {
        return basename($this->value);
    }

    public function getExtension(): string
    {
        return pathinfo($this->value, PATHINFO_EXTENSION);
    }

    public function withSuffix(string $suffix): self
    {
        return new self($this->value . $suffix);
    }

    public function append(string $segment): self
    {
        return new self(rtrim($this->value, '/') . '/' . ltrim($segment, '/'));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}