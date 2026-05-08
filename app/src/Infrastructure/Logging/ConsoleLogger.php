<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Minimal PSR-3 logger that writes structured log lines to STDOUT/STDERR.
 *
 * Format: [datetime] [LEVEL] message {json_context}
 *
 * No framework dependency — can be replaced by Monolog at any time since
 * the rest of the codebase depends only on Psr\Log\LoggerInterface.
 */
final class ConsoleLogger extends AbstractLogger
{
    private const STDERR_LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
    ];

    private const LEVEL_LABELS = [
        LogLevel::EMERGENCY => 'EMERGENCY',
        LogLevel::ALERT     => 'ALERT',
        LogLevel::CRITICAL  => 'CRITICAL',
        LogLevel::ERROR     => 'ERROR',
        LogLevel::WARNING   => 'WARNING',
        LogLevel::NOTICE    => 'NOTICE',
        LogLevel::INFO      => 'INFO',
        LogLevel::DEBUG     => 'DEBUG',
    ];

    public function __construct(
        private readonly string $minimumLevel = LogLevel::DEBUG,
        private readonly bool   $useColors    = true,
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if (!$this->shouldLog((string) $level)) {
            return;
        }

        $line = $this->format((string) $level, (string) $message, $context);
        $stream = in_array($level, self::STDERR_LEVELS, true) ? STDERR : STDOUT;

        fwrite($stream, $line . PHP_EOL);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function format(string $level, string $message, array $context): string
    {
        $timestamp = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $label     = self::LEVEL_LABELS[$level] ?? strtoupper($level);
        $label     = str_pad($label, 9);

        $interpolated = $this->interpolate($message, $context);

        $contextStr = '';
        if (!empty($context)) {
            $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $contextStr = ' ' . ($json !== false ? $json : '');
        }

        if ($this->useColors) {
            $label = $this->colorize($level, $label);
        }

        return sprintf('[%s] [%s] %s%s', $timestamp, $label, $interpolated, $contextStr);
    }

    /**
     * PSR-3 message interpolation: replace {key} with context values.
     *
     * @param array<string, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            $replacements['{' . $key . '}'] = match (true) {
                is_array($value)             => json_encode($value) ?: '[array]',
                $value instanceof \Throwable => $value->getMessage(),
                is_object($value)            => method_exists($value, '__toString')
                    ? (string) $value
                    : get_class($value),
                default                      => (string) $value,
            };
        }

        return strtr($message, $replacements);
    }

    private function shouldLog(string $level): bool
    {
        $levels = [
            LogLevel::DEBUG,
            LogLevel::INFO,
            LogLevel::NOTICE,
            LogLevel::WARNING,
            LogLevel::ERROR,
            LogLevel::CRITICAL,
            LogLevel::ALERT,
            LogLevel::EMERGENCY,
        ];

        $minIndex     = array_search($this->minimumLevel, $levels, true);
        $currentIndex = array_search($level, $levels, true);

        if ($minIndex === false || $currentIndex === false) {
            return true;
        }

        return $currentIndex >= $minIndex;
    }

    private function colorize(string $level, string $label): string
    {
        $color = match ($level) {
            LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL => "\033[1;37;41m", // bold white on red
            LogLevel::ERROR                                           => "\033[0;31m",    // red
            LogLevel::WARNING                                         => "\033[0;33m",    // yellow
            LogLevel::NOTICE                                          => "\033[0;36m",    // cyan
            LogLevel::INFO                                            => "\033[0;32m",    // green
            LogLevel::DEBUG                                           => "\033[0;37m",    // light gray
            default                                                   => "\033[0m",       // reset
        };

        return $color . $label . "\033[0m";
    }
}