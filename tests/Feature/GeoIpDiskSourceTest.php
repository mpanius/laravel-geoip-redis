<?php

declare(strict_types=1);

namespace Mpanius\GeoIpRedis\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Mpanius\GeoIpRedis\GeoIpRedis;
use Mpanius\GeoIpRedis\GeoIpRedisServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Redis;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Источник "файл на диске Laravel" (geoip-redis.source_disk).
 * Требует Redis 7+: GEOIP_TEST_REDIS_HOST / GEOIP_TEST_REDIS_PORT (по умолчанию 127.0.0.1:16391),
 * без Redis тесты пропускаются.
 */
class GeoIpDiskSourceTest extends TestCase
{
    private const KEYS = ['geoip-disk-test:v4', 'geoip-disk-test:v6', 'geoip-disk-test:meta'];

    private const PATH = 'geoip/ip-to-country.csv';

    private const CSV = "network,continent_code,country_code,country_name\n"
        . "8.8.8.0/24,NA,US,United States\n"
        . "77.88.0.0/18,EU,RU,Russia\n"
        . "2001:db8::/32,EU,DE,Germany\n";

    private ?Redis $raw = null;

    protected function getPackageProviders($app): array
    {
        return [GeoIpRedisServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.redis.client', 'phpredis');
        $app['config']->set('database.redis.geoip-disk-test', [
            'host' => getenv('GEOIP_TEST_REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('GEOIP_TEST_REDIS_PORT') ?: 16391),
            'database' => 0,
            'prefix' => '',
            'options' => ['serializer' => 0, 'compression' => 0],
        ]);
        $app['config']->set('geoip-redis.connection', 'geoip-disk-test');
        $app['config']->set('geoip-redis.key', 'geoip-disk-test:v4');
        $app['config']->set('geoip-redis.key_v6', 'geoip-disk-test:v6');
        $app['config']->set('geoip-redis.meta_key', 'geoip-disk-test:meta');
        $app['config']->set('geoip-redis.function_library', 'geoipdisktest');
        $app['config']->set('geoip-redis.function_name', 'geoip_disk_test_country');
        $app['config']->set('geoip-redis.ipv6_enabled', true);
        // The URL must never be touched when a disk is configured
        $app['config']->set('geoip-redis.download_url', 'http://127.0.0.1:1/never-used.csv');
        $app['config']->set('geoip-redis.source_disk', 'geoip-src');
        $app['config']->set('geoip-redis.source_path', self::PATH);
    }

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->raw = new Redis;
            $this->raw->connect(getenv('GEOIP_TEST_REDIS_HOST') ?: '127.0.0.1', (int) (getenv('GEOIP_TEST_REDIS_PORT') ?: 16391), 1.0);
            $this->raw->del(...self::KEYS);
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis is not available: ' . $e->getMessage());
        }

        Storage::fake('geoip-src');
    }

    protected function tearDown(): void
    {
        if ($this->raw !== null) {
            $this->raw->del(...self::KEYS);
            try {
                $this->raw->rawCommand('FUNCTION', 'DELETE', 'geoipdisktest');
            } catch (Throwable) {
            }
        }

        parent::tearDown();
    }

    private function service(): GeoIpRedis
    {
        return $this->app->make(GeoIpRedis::class);
    }

    private function putSource(string $content, ?int $mtime = null): void
    {
        $disk = Storage::disk('geoip-src');
        $disk->put(self::PATH, $content);

        if ($mtime !== null) {
            touch($disk->path(self::PATH), $mtime);
        }
    }

    #[Test]
    public function update_loads_ipv4_and_ipv6_from_the_disk_without_touching_the_url(): void
    {
        $this->putSource(self::CSV, 1_700_000_000);

        $result = $this->service()->update();

        $this->assertSame(2, $result->entriesCount);
        $this->assertSame(1, $result->ipv6EntriesCount);
        $this->assertSame('US', $this->service()->lookup('8.8.8.8'));
        $this->assertSame('RU', $this->service()->lookup('77.88.1.1'));
        $this->assertSame('DE', $this->service()->lookup('2001:db8::1'));

        $status = $this->service()->status();
        $this->assertSame('disk:geoip-src:' . self::PATH, $status['source']);
        $this->assertSame(1_700_000_000, $status['source_last_modified']);
        $this->assertSame(strlen(self::CSV), $status['source_size']);
    }

    #[Test]
    public function update_unpacks_a_zip_from_the_disk(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'geoip_zip_');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFromString('ip-to-country.csv', self::CSV);
        $zip->close();
        $this->putSource((string) file_get_contents($zipPath));
        @unlink($zipPath);

        $result = $this->service()->update();

        $this->assertSame(2, $result->entriesCount);
        $this->assertSame('US', $this->service()->lookup('8.8.8.8'));
    }

    #[Test]
    public function needs_update_follows_the_file_modification_time(): void
    {
        $this->putSource(self::CSV, 1_700_000_000);
        $service = $this->service();

        $this->assertTrue($service->needsUpdate(), 'never loaded');

        $service->update();
        $this->assertFalse($service->needsUpdate(), 'same file, interval not elapsed');

        touch(Storage::disk('geoip-src')->path(self::PATH), 1_700_000_100);
        $this->assertTrue($service->needsUpdate(), 'file is newer than the loaded one');

        $service->update();
        $this->assertFalse($service->needsUpdate());
    }

    #[Test]
    public function needs_update_notices_a_replaced_file_of_another_size(): void
    {
        $this->putSource(self::CSV, 1_700_000_000);
        $service = $this->service();
        $service->update();

        $this->putSource(self::CSV . "1.0.0.0/24,OC,AU,Australia\n", 1_700_000_000);

        $this->assertTrue($service->needsUpdate());
    }

    #[Test]
    public function needs_update_falls_back_to_the_interval_when_the_file_is_unchanged(): void
    {
        $this->putSource(self::CSV, 1_700_000_000);
        $service = $this->service();
        $service->update();

        $this->raw->hSet('geoip-disk-test:meta', 'updated_at', (string) (now()->timestamp - 25 * 3600));

        $this->assertTrue($service->needsUpdate());
    }

    #[Test]
    public function needs_update_is_true_when_data_was_loaded_from_another_source(): void
    {
        $this->putSource(self::CSV, 1_700_000_000);
        $service = $this->service();
        $service->update();

        $this->raw->hSet('geoip-disk-test:meta', 'source', 'https://example.test/ip-to-country.csv');

        $this->assertTrue($service->needsUpdate());
    }

    #[Test]
    public function needs_update_does_not_throw_when_the_file_disappeared(): void
    {
        $this->putSource(self::CSV, 1_700_000_000);
        $service = $this->service();
        $service->update();

        Storage::disk('geoip-src')->delete(self::PATH);

        $this->assertFalse($service->needsUpdate());
    }

    #[Test]
    public function missing_file_throws_and_keeps_the_previous_redis_data(): void
    {
        $this->putSource(self::CSV, 1_700_000_000);
        $this->service()->update();
        Storage::disk('geoip-src')->delete(self::PATH);

        try {
            $this->service()->update();
            $this->fail('RuntimeException expected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("disk 'geoip-src'", $e->getMessage());
            $this->assertStringContainsString(self::PATH, $e->getMessage());
        }

        $this->assertSame('US', $this->service()->lookup('8.8.8.8'));
        $this->assertSame(1_700_000_000, $this->service()->status()['source_last_modified']);
    }

    #[Test]
    public function command_fails_with_code_1_on_a_missing_file_and_keeps_old_data(): void
    {
        $this->putSource(self::CSV, 1_700_000_000);
        $this->service()->update();
        Storage::disk('geoip-src')->delete(self::PATH);

        $this->artisan('geoip:update', ['--force' => true])
            ->expectsOutputToContain('Update failed')
            ->assertExitCode(1);

        $this->assertSame('US', $this->service()->lookup('8.8.8.8'));
    }

    #[Test]
    public function command_loads_from_the_disk(): void
    {
        $this->putSource(self::CSV, 1_700_000_000);

        $this->artisan('geoip:update')
            ->expectsOutputToContain('Loading GeoIP database from disk')
            ->assertExitCode(0);

        $this->assertSame('RU', $this->service()->lookup('77.88.1.1'));
    }

    #[Test]
    public function without_source_disk_the_url_source_works_as_before(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'geoip_url_');
        file_put_contents($csv, self::CSV);
        config([
            'geoip-redis.source_disk' => null,
            'geoip-redis.download_url' => 'file://' . $csv,
            'geoip-redis.variant' => '',
        ]);

        try {
            $this->assertFalse($this->service()->usesDiskSource());
            $this->service()->update();
        } finally {
            @unlink($csv);
        }

        $status = $this->service()->status();
        $this->assertSame('file://' . $csv, $status['source']);
        $this->assertNull($status['source_last_modified']);
        $this->assertSame('US', $this->service()->lookup('8.8.8.8'));
    }
}
