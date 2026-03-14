<?php

declare(strict_types=1);

namespace Mpanius\GeoIpRedis;

class UpdateResult
{
    public function __construct(
        public readonly int $entriesCount,
        public readonly float $duration,
        public readonly int $skippedCount = 0,
    ) {}
}
