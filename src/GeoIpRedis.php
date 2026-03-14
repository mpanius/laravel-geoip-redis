<?php

declare(strict_types=1);

namespace Mpanius\GeoIpRedis;

use Illuminate\Support\Facades\Redis;
use Mpanius\GeoIpRedis\Support\CidrConverter;
use Mpanius\GeoIpRedis\Support\CsvDownloader;
use Redis as PhpRedis;
use RedisException;
use RuntimeException;

class GeoIpRedis
{
    /**
     * Cached phpredis client instance.
     */
    protected ?PhpRedis $redisClient = null;

    /**
     * Look up country code by IPv4 address.
     *
     * Uses FCALL_RO — the entire lookup happens inside Redis,
     * PHP only sends the command and receives the result string.
     *
     * @return string|null Two-letter country code (e.g. "RU", "US") or null
     */
    public function lookup(string $ip): ?string
    {
        $ipLong = CidrConverter::ipToLong($ip);
        if ($ipLong === null) {
            return null;
        }

        try {
            $result = $this->redis()->fcall_ro(
                $this->config('function_name'),
                [$this->config('key')],
                [(string) $ipLong],
            );
        } catch (RedisException) {
            return null;
        }

        return $result !== false ? (string) $result : null;
    }

    /**
     * Download CSV and load GeoIP data into Redis.
     *
     * 1. Downloads CSV to temp file
     * 2. Parses CIDR → integer ranges
     * 3. Loads into temp Redis key via pipeline
     * 4. Atomic RENAME to production key (zero downtime)
     * 5. Registers/updates Lua function
     * 6. Saves metadata
     */
    public function update(): UpdateResult
    {
        $startTime = microtime(true);

        // 1. Download CSV
        $csvPath = CsvDownloader::download(
            $this->config('download_url'),
            $this->config('api_key'),
            $this->config('variant'),
        );

        try {
            // 2+3. Parse and load into temp key
            $tempKey = $this->config('key') . ':loading';
            [$count, $skipped] = $this->loadCsvToRedis($csvPath, $tempKey);

            if ($count === 0) {
                $this->redis()->del($tempKey);
                throw new RuntimeException('CSV parsing resulted in zero entries — aborting to protect existing data.');
            }

            // 4. Atomic swap: RENAME temp → production (zero downtime)
            $this->redis()->rename($tempKey, $this->config('key'));

            // 5. Register/update Lua function
            $this->registerFunction();

            // 6. Save metadata
            $this->redis()->hMSet($this->config('meta_key'), [
                'updated_at' => (string) now()->timestamp,
                'entries_count' => (string) $count,
                'skipped_count' => (string) $skipped,
                'source' => $this->config('download_url'),
            ]);
        } finally {
            @unlink($csvPath);
        }

        $duration = round(microtime(true) - $startTime, 2);

        return new UpdateResult(
            entriesCount: $count,
            duration: $duration,
            skippedCount: $skipped,
        );
    }

    /**
     * Register the Lua function in Redis 7+ via FUNCTION LOAD.
     *
     * The function persists in RDB/AOF and survives Redis restarts.
     * REPLACE flag allows re-registering without errors.
     */
    public function registerFunction(): void
    {
        $luaPath = $this->luaScriptPath();

        if (! file_exists($luaPath)) {
            throw new RuntimeException("Lua script not found: {$luaPath}");
        }

        $luaCode = file_get_contents($luaPath);

        // Replace library name placeholder if config differs from default
        $libraryName = $this->config('function_library');
        if ($libraryName !== 'geoip') {
            $luaCode = preg_replace(
                '/^#!lua name=\w+/m',
                "#!lua name={$libraryName}",
                $luaCode,
            );
        }

        // Replace function name placeholder if config differs from default
        $functionName = $this->config('function_name');
        if ($functionName !== 'geoip_country') {
            $luaCode = str_replace(
                "function_name = 'geoip_country'",
                "function_name = '{$functionName}'",
                $luaCode,
            );
        }

        $this->redis()->function('LOAD', 'REPLACE', $luaCode);
    }

    /**
     * Check if GeoIP data needs updating based on configured interval.
     */
    public function needsUpdate(): bool
    {
        $meta = $this->status();

        if ($meta['updated_at'] === null) {
            return true;
        }

        $intervalSeconds = $this->config('update_interval_hours') * 3600;

        return (now()->timestamp - $meta['updated_at']) > $intervalSeconds;
    }

    /**
     * Get current GeoIP status and metadata.
     *
     * @return array{updated_at: int|null, entries_count: int, skipped_count: int, source: string|null, memory_bytes: int}
     */
    public function status(): array
    {
        $redis = $this->redis();

        $meta = $redis->hGetAll($this->config('meta_key'));
        $key = $this->config('key');

        // Get memory usage for the sorted set (Redis 4+)
        $memoryBytes = 0;
        try {
            $memoryBytes = (int) $redis->rawCommand('MEMORY', 'USAGE', $key);
        } catch (RedisException) {
            // Ignore if MEMORY command not available
        }

        return [
            'updated_at' => isset($meta['updated_at']) ? (int) $meta['updated_at'] : null,
            'entries_count' => isset($meta['entries_count']) ? (int) $meta['entries_count'] : 0,
            'skipped_count' => isset($meta['skipped_count']) ? (int) $meta['skipped_count'] : 0,
            'source' => $meta['source'] ?? null,
            'memory_bytes' => $memoryBytes,
            'sorted_set_card' => (int) $redis->zCard($key),
        ];
    }

    /**
     * Parse CSV and load entries into Redis Sorted Set via pipeline.
     *
     * @return array{0: int, 1: int} [loaded_count, skipped_count]
     */
    protected function loadCsvToRedis(string $csvPath, string $key): array
    {
        $redis = $this->redis();
        $redis->del($key);

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV file: {$csvPath}");
        }

        // Skip header row
        fgetcsv($handle);

        $batch = [];
        $count = 0;
        $skipped = 0;
        $batchSize = $this->config('pipeline_batch_size');
        $ipv6Enabled = $this->config('ipv6_enabled');

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 3) {
                $skipped++;
                continue;
            }

            $network = $row[0];
            $countryCode = $row[2];

            // Skip empty country codes
            if ($countryCode === '' || $countryCode === null) {
                $skipped++;
                continue;
            }

            // Skip IPv6 if disabled
            if (! $ipv6Enabled && str_contains($network, ':')) {
                $skipped++;
                continue;
            }

            // CIDR → start/end IP integers
            [$startIp, $endIp] = CidrConverter::toRange($network);
            if ($startIp === null) {
                $skipped++;
                continue;
            }

            // Score = end_ip, Member = "CC:start_ip"
            $batch[] = [$endIp, "{$countryCode}:{$startIp}"];
            $count++;

            if (count($batch) >= $batchSize) {
                $this->pipelineZadd($redis, $key, $batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->pipelineZadd($redis, $key, $batch);
        }

        fclose($handle);

        return [$count, $skipped];
    }

    /**
     * Batch ZADD via phpredis pipeline for maximum throughput.
     *
     * @param array<array{0: int, 1: string}> $batch
     */
    protected function pipelineZadd(PhpRedis $redis, string $key, array $batch): void
    {
        $pipe = $redis->pipeline();

        foreach ($batch as [$score, $member]) {
            $pipe->zAdd($key, $score, $member);
        }

        $pipe->exec();
    }

    /**
     * Get the path to the Lua script file.
     */
    protected function luaScriptPath(): string
    {
        return dirname(__DIR__) . '/resources/lua/geoip.lua';
    }

    /**
     * Get a config value.
     */
    protected function config(string $key): mixed
    {
        return config("geoip-redis.{$key}");
    }

    /**
     * Get the phpredis client for the configured connection.
     *
     * Returns the raw \Redis instance (not Laravel's wrapper)
     * for direct access to fcall_ro(), function(), pipeline(), etc.
     */
    protected function redis(): PhpRedis
    {
        if ($this->redisClient === null) {
            $connection = Redis::connection($this->config('connection'));

            /** @var PhpRedis $client */
            $client = $connection->client();
            $this->redisClient = $client;
        }

        return $this->redisClient;
    }
}
