<?php

declare(strict_types=1);

namespace Mpanius\GeoIpRedis;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mpanius\GeoIpRedis\Support\CidrConverter;
use Mpanius\GeoIpRedis\Support\CsvDownloader;
use Redis as PhpRedis;
use RedisException;
use RuntimeException;
use Throwable;

class GeoIpRedis
{
    /**
     * Cached phpredis client instance.
     */
    protected ?PhpRedis $redisClient = null;

    /**
     * Look up country code by IPv4 or IPv6 address.
     *
     * Uses FCALL_RO — the entire lookup happens inside Redis,
     * PHP only sends the command and receives the result string.
     * IPv4-mapped IPv6 (::ffff:a.b.c.d) is resolved as IPv4.
     *
     * @return string|null Two-letter country code (e.g. "RU", "US") or null
     */
    public function lookup(string $ip): ?string
    {
        $ipLong = CidrConverter::ipToLong($ip);
        if ($ipLong !== null) {
            return $this->fcall($this->config('key'), (string) $ipLong);
        }

        if (! str_contains($ip, ':')) {
            return null;
        }

        $hex = CidrConverter::ipv6ToHex($ip);
        if ($hex === null) {
            return null;
        }

        $mapped = CidrConverter::mappedIpv4ToLong($hex);
        if ($mapped !== null) {
            return $this->fcall($this->config('key'), (string) $mapped);
        }

        return $this->fcall($this->config('key_v6'), $hex);
    }

    protected function fcall(string $key, string $arg): ?string
    {
        try {
            $result = $this->redis()->fcall_ro($this->config('function_name'), [$key], [$arg]);
        } catch (RedisException) {
            return null;
        }

        return $result !== false ? (string) $result : null;
    }

    /**
     * Download CSV (or read it from the configured Storage disk) and load GeoIP data into Redis.
     */
    public function update(): UpdateResult
    {
        $startTime = microtime(true);
        $extraMeta = [];

        if ($this->usesDiskSource()) {
            [$csvPath, $extraMeta] = $this->fetchFromDisk();
        } else {
            $csvPath = CsvDownloader::download(
                $this->config('download_url'),
                $this->config('api_key'),
                $this->config('variant'),
            );
        }

        try {
            return $this->importCsv($csvPath, $startTime, $extraMeta);
        } finally {
            @unlink($csvPath);
        }
    }

    /**
     * True when the CSV comes from a Laravel Storage disk (geoip-redis.source_disk) instead of a URL.
     */
    public function usesDiskSource(): bool
    {
        return $this->sourceDisk() !== null;
    }

    protected function sourceDisk(): ?string
    {
        $disk = $this->config('source_disk');

        return is_string($disk) && $disk !== '' ? $disk : null;
    }

    protected function sourcePath(): string
    {
        return (string) $this->config('source_path');
    }

    /**
     * Value of meta field "source": URL or "disk:<disk>:<path>".
     */
    protected function sourceLabel(): string
    {
        return $this->usesDiskSource()
            ? 'disk:' . $this->sourceDisk() . ':' . $this->sourcePath()
            : (string) $this->config('download_url');
    }

    /**
     * Read the source file from the Storage disk into a temp CSV (ZIP is unpacked).
     *
     * lastModified/size are taken BEFORE reading: a file replaced mid-read is seen as newer next time.
     * Redis is not touched here, so a read error leaves the previous data intact.
     *
     * @return array{0: string, 1: array<string, string>} [temp csv path, extra meta fields]
     *
     * @throws RuntimeException
     */
    protected function fetchFromDisk(): array
    {
        $diskName = $this->sourceDisk();
        $path = $this->sourcePath();

        try {
            $disk = Storage::disk($diskName);

            if (! $disk->exists($path)) {
                throw new RuntimeException('file does not exist');
            }

            $lastModified = $disk->lastModified($path);
            $size = $disk->size($path);
            $stream = $disk->readStream($path);

            if (! is_resource($stream)) {
                throw new RuntimeException('cannot open file for reading');
            }

            try {
                $csvPath = CsvDownloader::fromStream($stream, 'source file');
            } finally {
                fclose($stream);
            }
        } catch (Throwable $e) {
            throw new RuntimeException("Cannot read GeoIP source '{$path}' from disk '{$diskName}': {$e->getMessage()}", 0, $e);
        }

        return [$csvPath, [
            'source_last_modified' => (string) $lastModified,
            'source_size' => (string) $size,
        ]];
    }

    /**
     * Load a CSV file (network,country_code) into Redis.
     *
     * 1. Parses CIDR → integer ranges (IPv4) / hex ranges (IPv6, if ipv6_enabled)
     * 2. Loads into temp Redis keys via pipeline
     * 3. Atomic RENAME to production keys (zero downtime)
     * 4. Registers/updates Lua function
     * 5. Saves metadata
     *
     * @param array<string, string> $extraMeta additional meta hash fields (disk source: source_last_modified, source_size)
     */
    public function importCsv(string $csvPath, ?float $startTime = null, array $extraMeta = []): UpdateResult
    {
        $startTime ??= microtime(true);

        $key = $this->config('key');
        $keyV6 = $this->config('key_v6');
        $tempKey = $key . ':loading';
        $tempKeyV6 = $keyV6 . ':loading';

        [$count, $countV6, $rowsV6, $skippedIpv6, $skippedEmpty, $skippedInvalid] = $this->loadCsvToRedis($csvPath, $tempKey, $tempKeyV6);

        if ($count === 0) {
            $this->redis()->del($tempKey, $tempKeyV6);
            throw new RuntimeException('CSV parsing resulted in zero entries — aborting to protect existing data.');
        }

        // Atomic swap: RENAME temp → production (zero downtime)
        $this->redis()->rename($tempKey, $key);

        // IPv6 swap only when the new data has v6 ranges; otherwise keep the previous key
        if ($countV6 > 0) {
            $this->redis()->rename($tempKeyV6, $keyV6);
        } else {
            $this->redis()->del($tempKeyV6);
        }

        $this->registerFunction();

        // File markers of a previous disk source must not outlive a switch back to the URL source
        if ($extraMeta === []) {
            $this->redis()->hDel($this->config('meta_key'), 'source_last_modified', 'source_size');
        }

        $this->redis()->hMSet($this->config('meta_key'), $extraMeta + [
            'updated_at' => (string) now()->timestamp,
            'entries_count' => (string) $count,
            'ipv6_entries_count' => (string) $countV6,
            'ipv6_rows_count' => (string) $rowsV6,
            'skipped_ipv6' => (string) $skippedIpv6,
            'skipped_empty' => (string) $skippedEmpty,
            'skipped_invalid' => (string) $skippedInvalid,
            'source' => $this->sourceLabel(),
        ]);

        return new UpdateResult(
            entriesCount: $count,
            duration: round(microtime(true) - $startTime, 2),
            skippedIpv6: $skippedIpv6,
            skippedEmpty: $skippedEmpty,
            skippedInvalid: $skippedInvalid,
            ipv6EntriesCount: $countV6,
            ipv6RowsCount: $rowsV6,
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
     * Check if GeoIP data needs updating.
     *
     * Disk source: true when the file is newer (or of a different size) than the one loaded,
     * or the loaded data came from another source; otherwise the configured interval decides.
     */
    public function needsUpdate(): bool
    {
        $meta = $this->status();

        if ($meta['updated_at'] === null) {
            return true;
        }

        if ($this->usesDiskSource() && $this->diskFileChanged($meta)) {
            return true;
        }

        $intervalSeconds = $this->config('update_interval_hours') * 3600;

        return (now()->timestamp - $meta['updated_at']) > $intervalSeconds;
    }

    /**
     * @param array{source: string|null, source_last_modified: int|null, source_size: int|null} $meta
     */
    protected function diskFileChanged(array $meta): bool
    {
        if ($meta['source'] !== $this->sourceLabel() || $meta['source_last_modified'] === null) {
            return true;
        }

        try {
            $disk = Storage::disk($this->sourceDisk());
            $lastModified = $disk->lastModified($this->sourcePath());
            $size = $disk->size($this->sourcePath());
        } catch (Throwable) {
            // Unreadable here -> let the interval decide; update() reports the real error
            return false;
        }

        return $lastModified > $meta['source_last_modified']
            || ($meta['source_size'] !== null && $size !== $meta['source_size']);
    }

    /**
     * Get current GeoIP status and metadata.
     *
     * @return array{updated_at: int|null, entries_count: int, ipv6_entries_count: int, ipv6_memory_bytes: int, ipv6_sorted_set_card: int, skipped_ipv6: int, skipped_empty: int, skipped_invalid: int, source: string|null, source_last_modified: int|null, source_size: int|null, memory_bytes: int, sorted_set_card: int}
     */
    public function status(): array
    {
        $redis = $this->redis();

        $meta = $redis->hGetAll($this->config('meta_key'));
        $key = $this->config('key');

        // Get memory usage for the sorted set (Redis 4+)
        $keyV6 = $this->config('key_v6');
        $memoryBytes = 0;
        $memoryBytesV6 = 0;
        try {
            $memoryBytes = (int) $redis->rawCommand('MEMORY', 'USAGE', $key);
            $memoryBytesV6 = (int) $redis->rawCommand('MEMORY', 'USAGE', $keyV6);
        } catch (RedisException) {
            // Ignore if MEMORY command not available
        }

        return [
            'updated_at' => isset($meta['updated_at']) ? (int) $meta['updated_at'] : null,
            'entries_count' => isset($meta['entries_count']) ? (int) $meta['entries_count'] : 0,
            'ipv6_entries_count' => isset($meta['ipv6_entries_count']) ? (int) $meta['ipv6_entries_count'] : 0,
            'skipped_ipv6' => isset($meta['skipped_ipv6']) ? (int) $meta['skipped_ipv6'] : 0,
            'skipped_empty' => isset($meta['skipped_empty']) ? (int) $meta['skipped_empty'] : 0,
            'skipped_invalid' => isset($meta['skipped_invalid']) ? (int) $meta['skipped_invalid'] : 0,
            'source' => $meta['source'] ?? null,
            'source_last_modified' => isset($meta['source_last_modified']) ? (int) $meta['source_last_modified'] : null,
            'source_size' => isset($meta['source_size']) ? (int) $meta['source_size'] : null,
            'memory_bytes' => $memoryBytes,
            'sorted_set_card' => (int) $redis->zCard($key),
            'ipv6_memory_bytes' => $memoryBytesV6,
            'ipv6_sorted_set_card' => (int) $redis->zCard($keyV6),
        ];
    }

    /**
     * Parse CSV and load entries into Redis Sorted Sets (IPv4 by score, IPv6 by lex order).
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int} [loaded_v4, stored_v6, rows_v6, skipped_ipv6, skipped_empty, skipped_invalid]
     */
    protected function loadCsvToRedis(string $csvPath, string $key, string $keyV6): array
    {
        $redis = $this->redis();
        $redis->del($key, $keyV6);

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV file: {$csvPath}");
        }

        // Parse header to find column indices dynamically
        // iplocate.io format: network,continent_code,country_code,country_name
        $header = fgetcsv($handle, null, ',', '"', '\\');
        if ($header === false) {
            fclose($handle);
            throw new RuntimeException('CSV file is empty or has no header row.');
        }

        $header = array_map('strtolower', array_map('trim', $header));
        $networkIdx = array_search('network', $header, true);
        $countryCodeIdx = array_search('country_code', $header, true);

        if ($networkIdx === false || $countryCodeIdx === false) {
            fclose($handle);
            throw new RuntimeException(
                'CSV header must contain "network" and "country_code" columns. Got: ' . implode(', ', $header),
            );
        }

        $batch = [];
        $rowsV6 = [];
        $count = 0;
        $skippedIpv6 = 0;
        $skippedEmpty = 0;
        $skippedInvalid = 0;
        $batchSize = $this->config('pipeline_batch_size');
        $ipv6Enabled = $this->config('ipv6_enabled');

        while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            if (count($row) <= max($networkIdx, $countryCodeIdx)) {
                $skippedInvalid++;
                continue;
            }

            $network = $row[$networkIdx];
            $countryCode = $row[$countryCodeIdx];

            // Skip empty country codes
            if ($countryCode === '' || $countryCode === null) {
                $skippedEmpty++;
                continue;
            }

            if (str_contains($network, ':')) {
                if (! $ipv6Enabled) {
                    $skippedIpv6++;
                    continue;
                }

                [$startHex, $endHex] = CidrConverter::toRangeV6($network);
                if ($startHex === null) {
                    $skippedInvalid++;
                    continue;
                }

                // Packed "start(16) end(16) cc": sorts by start, ~3x lighter than hex in PHP memory
                $rowsV6[] = hex2bin($startHex) . hex2bin($endHex) . $countryCode;

                continue;
            }

            // CIDR → start/end IP integers
            [$startIp, $endIp] = CidrConverter::toRange($network);
            if ($startIp === null) {
                $skippedInvalid++;
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

        $rowsCountV6 = count($rowsV6);
        $countV6 = $this->storeIpv6($redis, $keyV6, $rowsV6, $batchSize);

        return [$count, $countV6, $rowsCountV6, $skippedIpv6, $skippedEmpty, $skippedInvalid];
    }

    /**
     * Sort IPv6 ranges, merge contiguous ranges of the same country, ZADD in batches.
     *
     * Score = 0, member = "end_hex:start_hex:CC": lexicographic order of fixed-width hex
     * equals numeric order, so ZRANGEBYLEX finds the first range with end >= ip.
     * Merging is lossless for lookups (the union of adjacent same-country ranges answers
     * identically) and shrinks the set ~3x on the iplocate data.
     *
     * @param array<int, string> $rows packed "start(16 bytes) end(16 bytes) country_code"
     *
     * @return int number of stored ranges
     */
    protected function storeIpv6(PhpRedis $redis, string $key, array &$rows, int $batchSize): int
    {
        if ($rows === []) {
            return 0;
        }

        sort($rows, SORT_STRING);

        $stored = 0;
        $args = [];
        $curStart = $curEnd = $curCc = null;

        $emit = function () use (&$args, &$stored, $redis, $key, $batchSize, &$curStart, &$curEnd, &$curCc): void {
            $args[] = 0;
            $args[] = bin2hex($curEnd) . ':' . bin2hex($curStart) . ':' . $curCc;
            $stored++;

            if (count($args) >= $batchSize * 2) {
                $redis->zAdd($key, ...$args);
                $args = [];
            }
        };

        foreach ($rows as $row) {
            $start = substr($row, 0, 16);
            $end = substr($row, 16, 16);
            $cc = substr($row, 32);

            if ($curStart !== null && $cc === $curCc && $start === CidrConverter::incrementBinary($curEnd)) {
                $curEnd = $end;

                continue;
            }

            if ($curStart !== null) {
                $emit();
            }

            [$curStart, $curEnd, $curCc] = [$start, $end, $cc];
        }

        $emit();

        if ($args !== []) {
            $redis->zAdd($key, ...$args);
        }

        $rows = [];

        return $stored;
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
