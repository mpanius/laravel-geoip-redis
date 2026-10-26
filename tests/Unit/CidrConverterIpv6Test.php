<?php

declare(strict_types=1);

namespace Mpanius\GeoIpRedis\Tests\Unit;

use Mpanius\GeoIpRedis\Support\CidrConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CidrConverterIpv6Test extends TestCase
{
    #[Test]
    #[DataProvider('rangeProvider')]
    public function it_converts_ipv6_cidr_to_hex_range(string $cidr, ?string $start, ?string $end): void
    {
        $this->assertSame([$start, $end], CidrConverter::toRangeV6($cidr));
    }

    public static function rangeProvider(): array
    {
        return [
            '/32' => ['2001:db8::/32', '20010db8000000000000000000000000', '20010db8ffffffffffffffffffffffff'],
            '/128' => ['2001:db8::1/128', '20010db8000000000000000000000001', '20010db8000000000000000000000001'],
            '/0' => ['::/0', str_repeat('0', 32), str_repeat('f', 32)],
            'non-byte-aligned /35' => ['2001:db8:6000::/35', '20010db8600000000000000000000000', '20010db87fffffffffffffffffffffff'],
            'host bits cleared' => ['2001:db8::ff/120', '20010db8000000000000000000000000', '20010db80000000000000000000000ff'],
            'no slash' => ['2001:db8::', null, null],
            'prefix too big' => ['2001:db8::/129', null, null],
            'bad address' => ['2001:zz::/32', null, null],
            'ipv4 cidr' => ['8.8.8.0/24', null, null],
        ];
    }

    #[Test]
    public function it_normalizes_ipv6_addresses_to_hex(): void
    {
        $expected = '20010db8000000000000000000000001';

        $this->assertSame($expected, CidrConverter::ipv6ToHex('2001:db8::1'));
        $this->assertSame($expected, CidrConverter::ipv6ToHex('2001:0DB8:0000:0000:0000:0000:0000:0001'));
        $this->assertSame($expected, CidrConverter::ipv6ToHex('2001:db8:0:0:0:0:0:1'));
        $this->assertSame($expected, CidrConverter::ipv6ToHex('2001:db8::1%eth0'));
        $this->assertSame(str_repeat('0', 32), CidrConverter::ipv6ToHex('::'));
        $this->assertNull(CidrConverter::ipv6ToHex('not-an-ip'));
        $this->assertNull(CidrConverter::ipv6ToHex('8.8.8.8'));
        $this->assertNull(CidrConverter::ipv6ToHex('2001:db8::1::2'));
    }

    #[Test]
    public function it_extracts_ipv4_from_mapped_addresses(): void
    {
        $this->assertSame(ip2long('8.8.8.8'), CidrConverter::mappedIpv4ToLong(CidrConverter::ipv6ToHex('::ffff:8.8.8.8')));
        $this->assertSame(ip2long('8.8.8.8'), CidrConverter::mappedIpv4ToLong(CidrConverter::ipv6ToHex('::ffff:808:808')));
        $this->assertSame(0, CidrConverter::mappedIpv4ToLong(CidrConverter::ipv6ToHex('::ffff:0.0.0.0')));
        $this->assertNull(CidrConverter::mappedIpv4ToLong(CidrConverter::ipv6ToHex('2001:db8::1')));
        $this->assertNull(CidrConverter::mappedIpv4ToLong(CidrConverter::ipv6ToHex('::1')));
    }

    #[Test]
    public function it_increments_binary_numbers_with_carry(): void
    {
        $this->assertSame("\x00\x02", CidrConverter::incrementBinary("\x00\x01"));
        $this->assertSame("\x01\x00", CidrConverter::incrementBinary("\x00\xFF"));
        $this->assertNull(CidrConverter::incrementBinary("\xFF\xFF"));
    }
}
