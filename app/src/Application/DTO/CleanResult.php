<?php

declare(strict_types=1);

namespace App\Application\DTO;

/**
 * Result of a backup clean operation.
 */
final class CleanResult
{
    /**
     * @param string[] $deletedPaths
     */
    public function __construct(
        public readonly bool   $success,
        public readonly int    $deletedCount,
        public readonly array  $deletedPaths,
        public readonly int    $freedBytes,
        public readonly string $message,
    ) {}

    public static function empty(): self
    {
        return new self(
            success:      true,
            deletedCount: 0,
            deletedPaths: [],
            freedBytes:   0,
            message:      'No backups matched the retention policy.',
        );
    }

    public static function failure(string $reason): self
    {
        return new self(
            success:      false,
            deletedCount: 0,
            deletedPaths: [],
            freedBytes:   0,
            message:      $reason,
        );
    }
}