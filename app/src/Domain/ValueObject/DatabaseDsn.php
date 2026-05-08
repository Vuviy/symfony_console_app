<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Immutable, parsed database DSN value object.
 * Supports MySQL DSNs of the form:
 *   mysql://user:password@host:port/database
 */
final class DatabaseDsn
{
    private readonly string $driver;
    private readonly string $host;
    private readonly int    $port;
    private readonly string $database;
    private readonly string $username;
    private readonly string $password;

    public function __construct(
        string $driver,
        string $host,
        int    $port,
        string $database,
        string $username,
        string $password,
    ) {
        if ($driver === '') {
            throw new InvalidArgumentException('DSN driver must not be empty.');
        }

        if ($host === '') {
            throw new InvalidArgumentException('DSN host must not be empty.');
        }

        if ($database === '') {
            throw new InvalidArgumentException('DSN database must not be empty.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException(
                sprintf('DSN port must be between 1 and 65535, got %d.', $port)
            );
        }

        $this->driver   = $driver;
        $this->host     = $host;
        $this->port     = $port;
        $this->database = $database;
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Parse from a DSN URL string: mysql://user:pass@host:3306/dbname
     */
    public static function fromString(string $dsn): self
    {
        $parsed = parse_url($dsn);

        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'], $parsed['path'])) {
            throw new InvalidArgumentException(
                sprintf('Invalid DSN string: "%s".', $dsn)
            );
        }

        $database = ltrim($parsed['path'], '/');

        if ($database === '') {
            throw new InvalidArgumentException('DSN is missing the database name.');
        }

        return new self(
            driver:   $parsed['scheme'],
            host:     $parsed['host'],
            port:     (int) ($parsed['port'] ?? 3306),
            database: $database,
            username: urldecode($parsed['user'] ?? ''),
            password: urldecode($parsed['pass'] ?? ''),
        );
    }

    public function getDriver(): string   { return $this->driver; }
    public function getHost(): string     { return $this->host; }
    public function getPort(): int        { return $this->port; }
    public function getDatabase(): string { return $this->database; }
    public function getUsername(): string { return $this->username; }
    public function getPassword(): string { return $this->password; }

    /**
     * PDO-compatible DSN string (no credentials).
     */
    public function toPdoDsn(): string
    {
        return sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->driver,
            $this->host,
            $this->port,
            $this->database,
        );
    }

    /**
     * Safe representation without password.
     */
    public function toSafeString(): string
    {
        return sprintf(
            '%s://%s:***@%s:%d/%s',
            $this->driver,
            $this->username,
            $this->host,
            $this->port,
            $this->database,
        );
    }

    public function __toString(): string
    {
        return $this->toSafeString();
    }
}