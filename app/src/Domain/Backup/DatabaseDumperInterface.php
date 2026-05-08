<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Domain\ValueObject\DatabaseDsn;
use App\Domain\ValueObject\FilePath;

/**
 * Contract for creating a database dump.
 */
interface DatabaseDumperInterface
{
    /**
     * Dump the database described by $dsn to $destinationPath.
     *
     * @throws \RuntimeException when the dump fails.
     */
    public function dump(DatabaseDsn $dsn, FilePath $destinationPath): void;
}