<?php

declare(strict_types=1);

namespace App\Application\Clean;

use App\Application\DTO\CleanResult;
use App\Domain\Backup\BackupFile;
use App\Domain\Backup\BackupRepositoryInterface;
use App\Domain\Clean\RetentionPolicy;
use App\Domain\ValueObject\Timestamp;
use Psr\Log\LoggerInterface;
use Throwable;

final class CleanService implements CleanServiceInterface
{
    public function __construct(
        private readonly BackupRepositoryInterface $repository,
        private readonly LoggerInterface           $logger,
    ) {}

    public function clean(string $backupDir, int $retentionDays, int $minimumKeep = 3): CleanResult
    {
        $this->logger->info('Clean started.', [
            'backup_dir'     => $backupDir,
            'retention_days' => $retentionDays,
            'minimum_keep'   => $minimumKeep,
        ]);

        try {
            $policy = new RetentionPolicy(
                retentionDays: $retentionDays,
                minimumKeep:   $minimumKeep,
                now:           Timestamp::now(),
            );

            $allFiles  = $this->repository->findAll();
            $toDelete  = $policy->resolve($allFiles);

            if (count($toDelete) === 0) {
                $this->logger->info('No backups matched the deletion criteria.', [
                    'total_backups' => count($allFiles),
                ]);

                return CleanResult::empty();
            }

            $deletedPaths = [];
            $freedBytes   = 0;

            foreach ($toDelete as $backupFile) {
                $path  = (string) $backupFile->getPath();
                $bytes = $backupFile->getSize()->toBytes();

                $this->logger->info('Deleting backup.', [
                    'path'       => $path,
                    'size_bytes' => $bytes,
                    'created_at' => $backupFile->getCreatedAt()->toAtom(),
                ]);

                $this->repository->delete($backupFile);

                $deletedPaths[] = $path;
                $freedBytes    += $bytes;
            }

            $this->logger->info('Clean finished.', [
                'deleted_count' => count($deletedPaths),
                'freed_bytes'   => $freedBytes,
            ]);

            return new CleanResult(
                success:      true,
                deletedCount: count($deletedPaths),
                deletedPaths: $deletedPaths,
                freedBytes:   $freedBytes,
                message:      sprintf(
                    'Deleted %d backup(s), freed %d bytes.',
                    count($deletedPaths),
                    $freedBytes,
                ),
            );

        } catch (Throwable $throwable) {
            $this->logger->error('Clean failed.', [
                'error' => $throwable->getMessage(),
            ]);

            return CleanResult::failure($throwable->getMessage());
        }
    }
}