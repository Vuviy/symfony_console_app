<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Domain\ValueObject\ByteSize;
use App\Domain\ValueObject\FilePath;
use App\Domain\ValueObject\Timestamp;

/**
 * Represents a single backup file in the domain.
 * Pure data entity — no I/O, no framework coupling.
 */
final class BackupFile
{
    public function __construct(
        private readonly FilePath  $path,
        private readonly Timestamp $createdAt,
        private readonly ByteSize  $size,
    ) {}

    public function getPath(): FilePath
    {
        return $this->path;
    }

    public function getCreatedAt(): Timestamp
    {
        return $this->createdAt;
    }

    public function getSize(): ByteSize
    {
        return $this->size;
    }

    /**
     * Returns true when this backup is older than $days days relative to $now.
     */
    public function isOlderThan(int $days, Timestamp $now): bool
    {
        return $this->createdAt->diffInDays($now) > $days;
    }

    public function __toString(): string
    {
        return sprintf(
            'BackupFile{path=%s, created=%s, size=%s}',
            $this->path,
            $this->createdAt,
            $this->size,
        );
    }
}