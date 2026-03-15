<?php

declare(strict_types=1);

namespace Mpanius\GeoIpRedis;

class UpdateResult
{
    public function __construct(
        public readonly int $entriesCount,
        public readonly float $duration,
        public readonly int $skippedIpv6 = 0,
        public readonly int $skippedEmpty = 0,
        public readonly int $skippedInvalid = 0,
    ) {}

    public function totalSkipped(): int
    {
        return $this->skippedIpv6 + $this->skippedEmpty + $this->skippedInvalid;
    }
}
