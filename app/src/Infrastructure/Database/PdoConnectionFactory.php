<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Domain\ValueObject\DatabaseDsn;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Creates PDO connections from a DatabaseDsn value object.
 * This is the only place in the codebase that touches PDO directly.
 */
final class PdoConnectionFactory
{
    private const DEFAULT_OPTIONS = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 5,
    ];

    /**
     * Create and return a PDO connection.
     *
     * @throws RuntimeException on connection failure.
     */
    public function create(DatabaseDsn $dsn): PDO
    {
        try {
            return new PDO(
                $dsn->toPdoDsn(),
                $dsn->getUsername(),
                $dsn->getPassword(),
                self::DEFAULT_OPTIONS,
            );
        } catch (PDOException $exception) {
            throw new RuntimeException(
                sprintf(
                    'Failed to connect to database "%s": %s',
                    $dsn->toSafeString(),
                    $exception->getMessage(),
                ),
                (int) $exception->getCode(),
                $exception,
            );
        }
    }

    /**
     * Test whether the database is reachable without throwing.
     */
    public function isReachable(DatabaseDsn $dsn): bool
    {
        try {
            $this->create($dsn);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}