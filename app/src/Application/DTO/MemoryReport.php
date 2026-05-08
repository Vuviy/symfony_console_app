<?php

declare(strict_types=1);

namespace App\Application\DTO;

final class MemoryReport
{
    public function __construct(
        public readonly string $total,
        public readonly string $used,
        public readonly string $free,
        public readonly string $usedPercent,
        public readonly bool   $isWarning,
        public readonly bool   $isCritical,
    ) {}
}