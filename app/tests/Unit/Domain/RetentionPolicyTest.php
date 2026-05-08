<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Backup\BackupFile;
use App\Domain\Clean\RetentionPolicy;
use App\Domain\ValueObject\ByteSize;
use App\Domain\ValueObject\FilePath;
use App\Domain\ValueObject\Timestamp;
use App\Tests\AbstractTestCase;
use DateTimeImmutable;
use InvalidArgumentException;

final class RetentionPolicyTest extends AbstractTestCase
{
    private Timestamp $now;

    protected function setUp(): void
    {
        $this->now = Timestamp::fromString('2024-06-15T12:00:00+00:00');
    }

    private function makeBackup(string $path, int $daysAgo): BackupFile
    {
        $dt = (new DateTimeImmutable('2024-06-15T12:00:00+00:00'))
            ->modify(sprintf('-%d days', $daysAgo));

        return new BackupFile(
            path:      new FilePath($path),
            createdAt: new Timestamp($dt),
            size:      ByteSize::fromMegabytes(50),
        );
    }

    public function testDeletesOldBackupsAboveMinimumKeep(): void
    {
        $policy = new RetentionPolicy(retentionDays: 7, minimumKeep: 1, now: $this->now);

        $files = [
            $this->makeBackup('/backups/old_1.sql.gz', 30),
            $this->makeBackup('/backups/old_2.sql.gz', 20),
            $this->makeBackup('/backups/recent.sql.gz', 1),
        ];

        $toDelete = $policy->resolve($files);

        // recent.sql.gz is protected (minimumKeep=1, newest).
        // Both old files should be deleted.
        self::assertCount(2, $toDelete);

        $deletedPaths = array_map(
            static fn(BackupFile $f): string => (string) $f->getPath(),
            $toDelete
        );

        self::assertContains('/backups/old_1.sql.gz', $deletedPaths);
        self::assertContains('/backups/old_2.sql.gz', $deletedPaths);
    }

    public function testProtectsMinimumKeepCount(): void
    {
        $policy = new RetentionPolicy(retentionDays: 7, minimumKeep: 3, now: $this->now);

        $files = [
            $this->makeBackup('/backups/a.sql.gz', 30),
            $this->makeBackup('/backups/b.sql.gz', 20),
            $this->makeBackup('/backups/c.sql.gz', 10),
        ];

        // All 3 files are old, but minimumKeep=3 protects all of them.
        $toDelete = $policy->resolve($files);

        self::assertCount(0, $toDelete);
    }

    public function testReturnsEmptyWhenNoFilesMatchAge(): void
    {
        $policy = new RetentionPolicy(retentionDays: 30, minimumKeep: 0, now: $this->now);

        $files = [
            $this->makeBackup('/backups/new_1.sql.gz', 5),
            $this->makeBackup('/backups/new_2.sql.gz', 3),
        ];

        $toDelete = $policy->resolve($files);

        self::assertCount(0, $toDelete);
    }

    public function testEmptyFileListReturnsEmpty(): void
    {
        $policy   = new RetentionPolicy(retentionDays: 7, minimumKeep: 0, now: $this->now);
        $toDelete = $policy->resolve([]);

        self::assertCount(0, $toDelete);
    }

    public function testThrowsOnInvalidRetentionDays(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least 1/i');

        new RetentionPolicy(retentionDays: 0, minimumKeep: 1, now: $this->now);
    }

    public function testThrowsOnNegativeMinimumKeep(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/0 or greater/i');

        new RetentionPolicy(retentionDays: 7, minimumKeep: -1, now: $this->now);
    }

    public function testDoesNotDeleteWhenTotalBelowMinimumKeep(): void
    {
        $policy = new RetentionPolicy(retentionDays: 7, minimumKeep: 5, now: $this->now);

        $files = [
            $this->makeBackup('/backups/a.sql.gz', 10),
            $this->makeBackup('/backups/b.sql.gz', 9),
        ];

        // Only 2 files total, minimumKeep=5, so nothing should be deleted.
        $toDelete = $policy->resolve($files);

        self::assertCount(0, $toDelete);
    }
}