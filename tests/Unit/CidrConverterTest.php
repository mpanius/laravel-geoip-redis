<?php

declare(strict_types=1);

namespace Mpanius\GeoIpRedis\Tests\Unit;

use Mpanius\GeoIpRedis\Support\CidrConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CidrConverterTest extends TestCase
{
    #[Test]
    #[DataProvider('cidrRangeProvider')]
    public function it_converts_cidr_to_range(string $cidr, ?int $expectedStart, ?int $expectedEnd): void
    {
        [$start, $end] = CidrConverter::toRange($cidr);

        $this->assertSame($expectedStart, $start);
        $this->assertSame($expectedEnd, $end);
    }

    public static function cidrRangeProvider(): array
    {
        return [
            // Standard /24
            '8.8.8.0/24' => ['8.8.8.0/24', ip2long('8.8.8.0'), ip2long('8.8.8.255')],

            // Single IP /32
            '1.2.3.4/32' => ['1.2.3.4/32', ip2long('1.2.3.4'), ip2long('1.2.3.4')],

            // /16 block
            '192.168.0.0/16' => ['192.168.0.0/16', ip2long('192.168.0.0'), ip2long('192.168.255.255')],

            // /8 block
            '10.0.0.0/8' => ['10.0.0.0/8', ip2long('10.0.0.0'), ip2long('10.255.255.255')],

            // Edge case: /0 covers entire IPv4
            '0.0.0.0/0' => ['0.0.0.0/0', 0, 0xFFFFFFFF],

            // Real-world CIDR
            '1.0.0.0/24' => ['1.0.0.0/24', ip2long('1.0.0.0'), ip2long('1.0.0.255')],

            // Invalid: no slash
            'no-slash' => ['192.168.1.1', null, null],

            // Invalid: IPv6 returns null
            'ipv6' => ['2001:db8::/32', null, null],

            // Invalid: bad IP
            'bad-ip' => ['999.999.999.999/24', null, null],
        ];
    }

    #[Test]
    public function it_converts_ip_to_long(): void
    {
        $this->assertSame(134744072, CidrConverter::ipToLong('8.8.8.8'));
        $this->assertSame(ip2long('192.168.1.1'), CidrConverter::ipToLong('192.168.1.1'));
        $this->assertSame(ip2long('1.0.0.1'), CidrConverter::ipToLong('1.0.0.1'));
        $this->assertNull(CidrConverter::ipToLong('invalid'));
    }

    #[Test]
    public function it_converts_long_to_ip(): void
    {
        $this->assertSame('8.8.8.8', CidrConverter::longToIp(134744072));
        $this->assertSame('192.168.1.1', CidrConverter::longToIp(ip2long('192.168.1.1')));
    }

    #[Test]
    public function it_handles_roundtrip_conversion(): void
    {
        $testIps = ['0.0.0.0', '1.0.0.1', '8.8.8.8', '192.168.1.1', '255.255.255.255'];

        foreach ($testIps as $ip) {
            $long = CidrConverter::ipToLong($ip);
            $this->assertNotNull($long);
            $this->assertSame($ip, CidrConverter::longToIp($long));
        }
    }

    #[Test]
    public function cidr_start_is_always_less_or_equal_to_end(): void
    {
        $cidrs = ['1.0.0.0/24', '10.0.0.0/8', '192.168.0.0/16', '8.8.8.8/32', '0.0.0.0/0'];

        foreach ($cidrs as $cidr) {
            [$start, $end] = CidrConverter::toRange($cidr);
            $this->assertNotNull($start, "Start should not be null for {$cidr}");
            $this->assertLessThanOrEqual($end, $start, "Start should be <= end for {$cidr}");
        }
    }
}
