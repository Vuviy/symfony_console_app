<?php

declare(strict_types=1);

namespace App\Application\DTO;

/**
 * Data Transfer Object returned by BackupService::run().
 * Carries output data across layer boundaries — no domain types exposed.
 */
final class BackupResult
{
    public function __construct(
        public readonly bool   $success,
        public readonly string $backupPath,
        public readonly int    $fileSizeBytes,
        public readonly string $startedAt,
        public readonly string $finishedAt,
        public readonly string $message,
    ) {}

    public static function success(
        string $backupPath,
        int    $fileSizeBytes,
        string $startedAt,
        string $finishedAt,
    ): self {
        return new self(
            success:       true,
            backupPath:    $backupPath,
            fileSizeBytes: $fileSizeBytes,
            startedAt:     $startedAt,
            finishedAt:    $finishedAt,
            message:       'Backup completed successfully.',
        );
    }

    public static function failure(string $reason, string $startedAt): self
    {
        return new self(
            success:       false,
            backupPath:    '',
            fileSizeBytes: 0,
            startedAt:     $startedAt,
            finishedAt:    (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            message:       $reason,
        );
    }
}