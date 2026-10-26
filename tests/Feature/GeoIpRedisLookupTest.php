<?php

declare(strict_types=1);

namespace Mpanius\GeoIpRedis\Tests\Feature;

use Mpanius\GeoIpRedis\GeoIpRedis;
use Mpanius\GeoIpRedis\GeoIpRedisServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Redis;
use Throwable;

/**
 * Требует Redis 7+ (Functions). Адрес: GEOIP_TEST_REDIS_HOST / GEOIP_TEST_REDIS_PORT
 * (по умолчанию 127.0.0.1:16391), без Redis тесты пропускаются.
 */
class GeoIpRedisLookupTest extends TestCase
{
    private const KEYS = ['geoip-test:v4', 'geoip-test:v6', 'geoip-test:meta'];

    private ?Redis $raw = null;

    protected function getPackageProviders($app): array
    {
        return [GeoIpRedisServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.redis.client', 'phpredis');
        $app['config']->set('database.redis.geoip-test', [
            'host' => getenv('GEOIP_TEST_REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('GEOIP_TEST_REDIS_PORT') ?: 16391),
            'database' => 0,
            'prefix' => '',
            'options' => ['serializer' => 0, 'compression' => 0],
        ]);
        $app['config']->set('geoip-redis.connection', 'geoip-test');
        $app['config']->set('geoip-redis.key', 'geoip-test:v4');
        $app['config']->set('geoip-redis.key_v6', 'geoip-test:v6');
        $app['config']->set('geoip-redis.meta_key', 'geoip-test:meta');
        $app['config']->set('geoip-redis.function_library', 'geoiptest');
        $app['config']->set('geoip-redis.function_name', 'geoip_test_country');
        $app['config']->set('geoip-redis.ipv6_enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->raw = new Redis;
            $this->raw->connect(getenv('GEOIP_TEST_REDIS_HOST') ?: '127.0.0.1', (int) (getenv('GEOIP_TEST_REDIS_PORT') ?: 16391), 1.0);
            $this->raw->del(...self::KEYS);
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis is not available: '.$e->getMessage());
        }

        $csv = tempnam(sys_get_temp_dir(), 'geoip_test_');
        file_put_contents($csv, implode("\n", [
            'network,continent_code,country_code,country_name',
            '1.0.0.0/24,OC,AU,Australia',
            '8.8.8.0/24,NA,US,United States',
            '77.88.0.0/18,EU,RU,Russia',
            '255.255.255.0/24,NA,ZZ,Last',
            '2001:db8::/32,EU,DE,Germany',
            '2001:db9::/32,EU,FR,France',
            '2a02:6b8::/32,EU,RU,Russia',
            '2a02:6b9::/32,,,',
            '2600:1f00::/24,NA,US,United States',
            '2600:2000::1/128,NA,CA,Canada',
            'ffff:ffff:ffff:ffff:ffff:ffff:ffff:fff0/124,NA,XX,Top',
            '::/128,NA,AA,Zero',
            '',
        ]));
        $this->csv = $csv;
    }

    private string $csv;

    protected function tearDown(): void
    {
        if ($this->raw !== null) {
            $this->raw->del(...self::KEYS);
            try {
                $this->raw->rawCommand('FUNCTION', 'DELETE', 'geoiptest');
            } catch (Throwable) {
            }
        }
        if (isset($this->csv)) {
            @unlink($this->csv);
        }

        parent::tearDown();
    }

    private function loaded(): GeoIpRedis
    {
        $service = $this->app->make(GeoIpRedis::class);
        $service->importCsv($this->csv);

        return $service;
    }

    #[Test]
    #[DataProvider('ipv4Provider')]
    public function ipv4_lookup_is_unchanged(string $ip, ?string $expected): void
    {
        $this->assertSame($expected, $this->loaded()->lookup($ip));
    }

    public static function ipv4Provider(): array
    {
        return [
            'range start' => ['1.0.0.0', 'AU'],
            'inside' => ['8.8.8.8', 'US'],
            'range end' => ['8.8.8.255', 'US'],
            'before range' => ['8.8.7.255', null],
            'after range' => ['8.8.9.0', null],
            'gap' => ['10.0.0.1', null],
            'top of space' => ['255.255.255.255', 'ZZ'],
            'invalid' => ['999.1.1.1', null],
            'garbage' => ['hello', null],
        ];
    }

    #[Test]
    #[DataProvider('ipv6Provider')]
    public function ipv6_lookup(string $ip, ?string $expected): void
    {
        $this->assertSame($expected, $this->loaded()->lookup($ip));
    }

    public static function ipv6Provider(): array
    {
        return [
            'range start' => ['2001:db8::', 'DE'],
            'inside' => ['2001:db8:1234:5678::1', 'DE'],
            'range end' => ['2001:db8:ffff:ffff:ffff:ffff:ffff:ffff', 'DE'],
            'adjacent range start' => ['2001:db9::', 'FR'],
            'just before range' => ['2001:db7:ffff:ffff:ffff:ffff:ffff:ffff', null],
            'gap between ranges' => ['2001:dba::1', null],
            'uppercase and long form' => ['2A02:06B8:0000:0000:0000:0000:0000:0001', 'RU'],
            'non-byte-aligned /24 inside' => ['2600:1f80::1', 'US'],
            'non-byte-aligned /24 end' => ['2600:1fff:ffff::1', 'US'],
            'non-byte-aligned /24 after' => ['2600:2000::', null],
            'single host /128' => ['2600:2000::1', 'CA'],
            'neighbour of /128 below' => ['2600:2000::', null],
            'neighbour of /128 above' => ['2600:2000::2', null],
            'empty country skipped' => ['2a02:6b9::1', null],
            'zero address' => ['::', 'AA'],
            'loopback is not in the data' => ['::1', null],
            'top of space' => ['ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', 'XX'],
            'zone id ignored' => ['2001:db8::1%eth0', 'DE'],
            'invalid' => ['2001:db8::1::2', null],
        ];
    }

    #[Test]
    public function ipv4_mapped_ipv6_is_resolved_as_ipv4(): void
    {
        $service = $this->loaded();

        $this->assertSame('US', $service->lookup('::ffff:8.8.8.8'));
        $this->assertSame('US', $service->lookup('::ffff:808:808'));
        $this->assertSame('AU', $service->lookup('0:0:0:0:0:ffff:1.0.0.1'));
        $this->assertNull($service->lookup('::ffff:10.0.0.1'));
    }

    #[Test]
    public function import_reports_counts_and_writes_meta(): void
    {
        $service = $this->app->make(GeoIpRedis::class);
        $result = $service->importCsv($this->csv);

        $this->assertSame(4, $result->entriesCount);
        $this->assertSame(7, $result->ipv6EntriesCount);
        $this->assertSame(1, $result->skippedEmpty);
        $this->assertSame(0, $result->skippedIpv6);

        $this->assertSame(4, $this->raw->zCard('geoip-test:v4'));
        $this->assertSame(7, $this->raw->zCard('geoip-test:v6'));
        $this->assertSame('7', $this->raw->hGet('geoip-test:meta', 'ipv6_entries_count'));

        $status = $service->status();
        $this->assertSame(7, $status['ipv6_entries_count']);
        $this->assertSame(7, $status['ipv6_sorted_set_card']);
        $this->assertGreaterThan(0, $status['ipv6_memory_bytes']);
        $this->assertSame([], $this->raw->keys('geoip-test:*:loading'));
    }

    #[Test]
    public function ipv6_rows_are_skipped_when_disabled(): void
    {
        config(['geoip-redis.ipv6_enabled' => false]);

        $result = $this->app->make(GeoIpRedis::class)->importCsv($this->csv);

        $this->assertSame(7, $result->skippedIpv6);
        $this->assertSame(0, $result->ipv6EntriesCount);
        $this->assertSame(0, $this->raw->exists('geoip-test:v6'));
        $this->assertSame('US', $this->app->make(GeoIpRedis::class)->lookup('::ffff:8.8.8.8'));
        $this->assertNull($this->app->make(GeoIpRedis::class)->lookup('2001:db8::1'));
    }

    #[Test]
    public function reimport_replaces_ipv6_data_atomically(): void
    {
        $service = $this->loaded();
        $this->assertSame('DE', $service->lookup('2001:db8::1'));

        file_put_contents($this->csv, "network,country_code\n1.0.0.0/24,AU\n2001:db8::/32,AT\n");
        $service->importCsv($this->csv);

        $this->assertSame('AT', $service->lookup('2001:db8::1'));
        $this->assertNull($service->lookup('2a02:6b8::1'));
        $this->assertSame(1, $this->raw->zCard('geoip-test:v6'));
    }

    #[Test]
    public function legacy_decimal_argument_still_works_on_the_lua_function(): void
    {
        $this->loaded();

        $this->assertSame('US', $this->raw->fcall_ro('geoip_test_country', ['geoip-test:v4'], [(string) ip2long('8.8.8.8')]));
    }

    #[Test]
    public function contiguous_ipv6_ranges_of_one_country_are_merged_without_changing_lookups(): void
    {
        file_put_contents($this->csv, implode("\n", [
            'network,country_code',
            '1.0.0.0/24,AU',
            // unsorted on purpose: db8:2 + db8:0 + db8:1 are contiguous DE, db8:3 is FR, db8:5 is DE after a gap
            '2001:db8:2::/48,DE',
            '2001:db8::/48,DE',
            '2001:db8:1::/48,DE',
            '2001:db8:3::/48,FR',
            '2001:db8:5::/48,DE',
            '',
        ]));

        $service = $this->app->make(GeoIpRedis::class);
        $result = $service->importCsv($this->csv);

        $this->assertSame(5, $result->ipv6RowsCount);
        $this->assertSame(3, $result->ipv6EntriesCount);
        $this->assertSame(3, $this->raw->zCard('geoip-test:v6'));

        $this->assertSame('DE', $service->lookup('2001:db8::'));
        $this->assertSame('DE', $service->lookup('2001:db8:1:ffff::1'));
        $this->assertSame('DE', $service->lookup('2001:db8:2:ffff:ffff:ffff:ffff:ffff'));
        $this->assertSame('FR', $service->lookup('2001:db8:3::1'));
        $this->assertNull($service->lookup('2001:db8:4::1'));
        $this->assertSame('DE', $service->lookup('2001:db8:5::1'));
        $this->assertNull($service->lookup('2001:db8:6::'));
    }
}
