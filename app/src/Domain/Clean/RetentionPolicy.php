<?php

declare(strict_types=1);

namespace App\Domain\Clean;

use App\Domain\Backup\BackupFile;
use App\Domain\ValueObject\Timestamp;

/**
 * Retention policy that marks backups for deletion based on age.
 * Pure domain logic — no I/O, no framework.
 */
final class RetentionPolicy implements CleanPolicyInterface
{
    /**
     * @param int       $retentionDays  Backups older than this will be marked for deletion.
     * @param int       $minimumKeep    Always keep at least this many backups, regardless of age.
     * @param Timestamp $now            Reference timestamp (injectable for testing).
     */
    public function __construct(
        private readonly int       $retentionDays,
        private readonly int       $minimumKeep,
        private readonly Timestamp $now,
    ) {
        if ($retentionDays < 1) {
            throw new \InvalidArgumentException('Retention days must be at least 1.');
        }

        if ($minimumKeep < 0) {
            throw new \InvalidArgumentException('Minimum keep must be 0 or greater.');
        }
    }

    /**
     * @param BackupFile[] $files
     * @return BackupFile[]
     */
    public function resolve(array $files): array
    {
        if (count($files) <= $this->minimumKeep) {
            return [];
        }

        // Sort: newest first so we can always keep $minimumKeep most-recent.
        usort($files, static function (BackupFile $a, BackupFile $b): int {
            return $b->getCreatedAt()->toDateTimeImmutable()
                <=> $a->getCreatedAt()->toDateTimeImmutable();
        });

        // Files we must always keep (newest $minimumKeep).
        $protected = array_slice($files, 0, $this->minimumKeep);
        $candidates = array_slice($files, $this->minimumKeep);

        $protectedPaths = array_map(
            static fn(BackupFile $f): string => (string) $f->getPath(),
            $protected
        );

        $toDelete = [];
        foreach ($candidates as $file) {
            $isProtected = in_array((string) $file->getPath(), $protectedPaths, true);

            if (!$isProtected && $file->isOlderThan($this->retentionDays, $this->now)) {
                $toDelete[] = $file;
            }
        }

        return $toDelete;
    }
}