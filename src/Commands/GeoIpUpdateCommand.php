<?php

declare(strict_types=1);

namespace Mpanius\GeoIpRedis\Commands;

use Illuminate\Console\Command;
use Mpanius\GeoIpRedis\GeoIpRedis;
use Throwable;

class GeoIpUpdateCommand extends Command
{
    protected $signature = 'geoip:update
        {--force : Force update even if interval has not elapsed}
        {--status : Show current status without updating}
        {--register-function : Only register/update the Lua function in Redis}';

    protected $description = 'Download and load GeoIP ip-to-country data into Redis';

    public function handle(GeoIpRedis $service): int
    {
        if ($this->option('status')) {
            return $this->displayStatus($service);
        }

        if ($this->option('register-function')) {
            return $this->registerFunction($service);
        }

        if (! $this->option('force') && ! $service->needsUpdate()) {
            $status = $service->status();
            $updatedAt = $status['updated_at']
                ? date('Y-m-d H:i:s', $status['updated_at'])
                : 'never';

            $this->info("GeoIP data is up to date (last update: {$updatedAt}). Use --force to override.");

            return self::SUCCESS;
        }

        $this->info('Downloading GeoIP database...');

        try {
            $result = $service->update();
        } catch (Throwable $e) {
            $this->error("Update failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Loaded {$result->entriesCount} IPv4 entries in {$result->duration}s");

        if ($result->skippedIpv6 > 0) {
            $this->comment("  Skipped {$result->skippedIpv6} IPv6 entries (ipv6_enabled=false)");
        }
        if ($result->skippedEmpty > 0) {
            $this->comment("  Skipped {$result->skippedEmpty} entries with empty country code");
        }
        if ($result->skippedInvalid > 0) {
            $this->warn("  Skipped {$result->skippedInvalid} invalid/malformed entries");
        }

        return self::SUCCESS;
    }

    protected function displayStatus(GeoIpRedis $service): int
    {
        $status = $service->status();

        $updatedAt = $status['updated_at']
            ? date('Y-m-d H:i:s', $status['updated_at'])
            : 'never';

        $memoryMb = round($status['memory_bytes'] / 1024 / 1024, 2);

        $this->table(
            ['Parameter', 'Value'],
            [
                ['Last update', $updatedAt],
                ['IPv4 entries (loaded)', number_format($status['entries_count'])],
                ['Sorted set cardinality', number_format($status['sorted_set_card'])],
                ['Skipped: IPv6', number_format($status['skipped_ipv6'])],
                ['Skipped: empty country', number_format($status['skipped_empty'])],
                ['Skipped: invalid', number_format($status['skipped_invalid'])],
                ['Memory usage', "{$memoryMb} MB"],
                ['Source', $status['source'] ?? 'N/A'],
                ['Needs update', $service->needsUpdate() ? 'YES' : 'no'],
                ['Connection', config('geoip-redis.connection')],
                ['Redis key', config('geoip-redis.key')],
            ],
        );

        return self::SUCCESS;
    }

    protected function registerFunction(GeoIpRedis $service): int
    {
        try {
            $service->registerFunction();
            $this->info('Lua function registered successfully.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Failed to register function: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
