<?php

declare(strict_types=1);

namespace Mpanius\GeoIpRedis\Support;

class CidrConverter
{
    /**
     * Convert CIDR notation to [start_ip, end_ip] as unsigned integers.
     *
     * "1.0.0.0/24" → [16777216, 16777471]
     *
     * @return array{0: int|null, 1: int|null}
     */
    public static function toRange(string $cidr): array
    {
        if (! str_contains($cidr, '/')) {
            return [null, null];
        }

        [$ip, $prefix] = explode('/', $cidr, 2);
        $prefix = (int) $prefix;

        // IPv6 — not supported in this method
        if (str_contains($ip, ':')) {
            return [null, null];
        }

        if ($prefix < 0 || $prefix > 32) {
            return [null, null];
        }

        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return [null, null];
        }

        // On 64-bit PHP, ip2long returns positive integers
        // For /0 edge case: mask would be 0, covering entire IPv4 space
        if ($prefix === 0) {
            return [0, 0xFFFFFFFF];
        }

        $mask = (-1 << (32 - $prefix)) & 0xFFFFFFFF;
        $start = $ipLong & $mask;
        $end = $start | (~$mask & 0xFFFFFFFF);

        return [$start, $end];
    }

    /**
     * Convert a dotted IPv4 address to an unsigned 32-bit integer.
     *
     * "8.8.8.8" → 134744072
     */
    public static function ipToLong(string $ip): ?int
    {
        $result = ip2long($ip);

        return $result !== false ? $result : null;
    }

    /**
     * Convert an unsigned 32-bit integer back to dotted IPv4 notation.
     *
     * 134744072 → "8.8.8.8"
     */
    public static function longToIp(int $num): string
    {
        return long2ip($num);
    }
}
