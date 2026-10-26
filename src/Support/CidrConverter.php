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
     * Convert an IPv6 CIDR to [start, end] as 32-char lowercase hex strings.
     *
     * "2001:db8::/32" → ["20010db8000000000000000000000000", "20010db8ffffffffffffffffffffffff"]
     *
     * Hex of fixed width sorts lexicographically exactly like the 128-bit number,
     * which is what the Redis lex index relies on. No GMP/bcmath needed.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function toRangeV6(string $cidr): array
    {
        if (! str_contains($cidr, '/')) {
            return [null, null];
        }

        [$ip, $prefix] = explode('/', $cidr, 2);

        if (! ctype_digit($prefix) || (int) $prefix > 128) {
            return [null, null];
        }

        $bytes = self::ipv6ToBinary($ip);
        if ($bytes === null) {
            return [null, null];
        }

        $prefix = (int) $prefix;
        $start = '';
        $end = '';

        foreach (str_split($bytes) as $i => $byte) {
            $bits = max(0, min(8, $prefix - 8 * $i));
            $mask = $bits === 0 ? 0 : (0xFF << (8 - $bits)) & 0xFF;
            $start .= chr(ord($byte) & $mask);
            $end .= chr(ord($byte) | (~$mask & 0xFF));
        }

        return [bin2hex($start), bin2hex($end)];
    }

    /**
     * Normalize an IPv6 address (any valid notation, optional %zone) to 32-char lowercase hex.
     */
    public static function ipv6ToHex(string $ip): ?string
    {
        $bytes = self::ipv6ToBinary($ip);

        return $bytes === null ? null : bin2hex($bytes);
    }

    /**
     * If the 32-char hex is an IPv4-mapped address (::ffff:a.b.c.d), return the IPv4 as an integer.
     */
    public static function mappedIpv4ToLong(?string $hex): ?int
    {
        if ($hex === null || strlen($hex) !== 32 || ! str_starts_with($hex, '00000000000000000000ffff')) {
            return null;
        }

        return (int) hexdec(substr($hex, 24));
    }

    /**
     * Add 1 to a big-endian binary number; null on overflow (all 0xFF).
     */
    public static function incrementBinary(string $bytes): ?string
    {
        for ($i = strlen($bytes) - 1; $i >= 0; $i--) {
            if ($bytes[$i] !== "\xFF") {
                $bytes[$i] = chr(ord($bytes[$i]) + 1);

                return $bytes;
            }

            $bytes[$i] = "\x00";
        }

        return null;
    }

    protected static function ipv6ToBinary(string $ip): ?string
    {
        $zone = strpos($ip, '%');
        if ($zone !== false) {
            $ip = substr($ip, 0, $zone);
        }

        if (! str_contains($ip, ':') || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        $bytes = inet_pton($ip);

        return $bytes === false ? null : $bytes;
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
