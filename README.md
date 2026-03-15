# laravel-geoip-redis

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Native Redis GeoIP lookup for Laravel using Redis 7+ Functions (`FCALL_RO`) and Sorted Sets.

**The entire lookup happens inside Redis** — PHP only sends a command and receives a two-letter country code. Zero parsing at request time.

[README на русском](README_RU.md)

## Why this package?

There are several ways to resolve a client IP to a country. Each has trade-offs:

| Approach | Where lookup runs | Latency | Multi-node | Data freshness | Setup effort |
|---|---|---|---|---|---|
| **This package** | Redis (FCALL_RO) | <1 ms | Shared Redis, update once | Daily CSV auto-update | Medium |
| **nginx geoip2 module** | nginx (C, in-process) | ~0 ms | Per-node mmdb file | Manual download + reload | High (recompile/module) |
| **Cloudflare CF-IPCountry** | Cloudflare edge | 0 ms (header) | Automatic | Always current | Low (enable toggle) |
| **Torann/laravel-geoip** | PHP (MaxMind Reader) | <1 ms | Per-node mmdb file | Manual or `geoip:update` | Medium |
| **MaxMind GeoIP2 PHP** | PHP (binary search in mmdb) | <1 ms | Per-node mmdb file | Manual download | Medium |
| **API services** (ipinfo, iplocate, ip-api) | External HTTP | 50–200 ms | N/A | Always current | Low |

### Key differences

**vs nginx geoip2 module**
- nginx module is the fastest (~0 ms, in-process C code) but requires a `.mmdb` file **on every node**, nginx recompilation or dynamic module loading, and a manual reload after each database update. This package loads data into shared Redis once — all nodes read from it immediately, no nginx reload needed.

**vs Cloudflare CF-IPCountry**
- Cloudflare is zero-effort if you're already behind Cloudflare. But it only works when traffic goes through CF. Internal traffic, health checks, queue workers, and non-CF environments get no header. This package works for any IP, from any context (HTTP, CLI, queues).

**vs Torann/laravel-geoip (MaxMind)**
- laravel-geoip loads the `.mmdb` file into PHP process memory and runs binary search in PHP. Each FPM/Octane worker holds its own copy in RAM. This package offloads all parsing to Redis — PHP workers stay lightweight. With Octane/FrankenPHP (long-lived processes), this prevents mmdb memory duplication across workers.

**vs API services**
- HTTP API calls add 50–200 ms latency per request and require rate limiting / caching. This package is a single Redis command (<1 ms) with no external dependency at request time.

### Multi-node architecture

```
┌─────────────┐
│  Scheduler   │  php artisan geoip:update (runs once, daily)
│  (any node)  │──────────────┐
└─────────────┘              │
                              ▼
                     ┌─────────────────┐
                     │   Shared Redis   │  Sorted Set + Lua Function
                     │   (common host)  │  stored globally in Redis
                     └────────┬────────┘
                              │ FCALL_RO
              ┌───────────────┼───────────────┐
              ▼               ▼               ▼
        ┌──────────┐   ┌──────────┐   ┌──────────┐
        │  App #1  │   │  App #2  │   │  App #3  │
        │ (FPM/    │   │ (FPM/    │   │ (FPM/    │
        │  Octane) │   │  Octane) │   │  Octane) │
        └──────────┘   └──────────┘   └──────────┘
```

- **Update once**: `geoip:update` runs on a single node (scheduler, cron, or manually). Data is written to shared Redis.
- **Read from anywhere**: All application nodes call `FCALL_RO` against the same Redis. The Lua function is registered globally in Redis, not per-connection.
- **No file sync needed**: Unlike mmdb-based solutions, there is no need to distribute database files across nodes.
- **Read replicas**: If your Redis has replicas, `FCALL_RO` (with `no-writes` flag) can execute on replicas, offloading the primary.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- **Redis 7+** (for `FUNCTION LOAD` / `FCALL_RO`)
- **ext-redis** (phpredis) — Predis is not supported (no native `fcall_ro()`)

### Important prerequisites

| Requirement | Why |
|---|---|
| **Redis 7+** | `FUNCTION LOAD` / `FCALL_RO` were introduced in Redis 7.0. Earlier versions do not support server-side functions. Check with `redis-cli INFO server \| grep redis_version`. |
| **phpredis** (not Predis) | The package uses `fcall_ro()`, `function()`, and `pipeline()` — native phpredis methods. Predis does not expose these. |
| **Dedicated Redis connection** | Must use `serializer=NONE` and `compression=NONE`. See [Configuration](#configuration) for details. |
| **Trusted Proxies** | `request()->ip()` must return the real client IP, not a load balancer or CDN address. See [Trusted Proxies](#trusted-proxies). |
| **Network access** | The server running `geoip:update` must be able to reach the CSV download URL (iplocate.io by default). |

### Redis-compatible alternatives

This package requires `FUNCTION LOAD` and `FCALL_RO` commands (Redis 7.0+). Not all Redis-compatible databases support them:

| Database | FCALL_RO support | Notes |
|---|---|---|
| **Redis** 7+ | Yes | Full support. The reference implementation. |
| **Valkey** 7+ | Yes | Redis fork by Linux Foundation. Full FCALL_RO support since 7.0.0. |
| **Apache Kvrocks** 2.7+ | Yes | Supported since v2.7.0, including read-only replica execution. |
| **Dragonfly** | **No** | Only supports Lua scripting (`EVAL`/`EVALSHA`). `FUNCTION LOAD` / `FCALL_RO` are not implemented. |
| **KeyDB** | **No** | Based on Redis 6.x codebase. Does not support Redis 7 Functions. |
| **Amazon ElastiCache** | Check version | Supported only on Redis 7+ engine. Serverless or version ≥ 7.0 required. |
| **Azure Cache for Redis** | Check tier | Enterprise and Premium tiers with Redis 7+ engine. |
| **Google Memorystore** | Check version | Supported if running Redis 7+ engine. |

> **If your Redis alternative does not support FCALL_RO**, this package will not work. There is no EVAL/EVALSHA fallback by design — FCALL_RO provides persistence, read-replica support, and better performance that EVAL cannot match.

## Installation

```bash
composer require mpanius/laravel-geoip-redis
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=geoip-redis-config
```

## Configuration

Add to your `.env`:

```env
GEOIP_REDIS_CONNECTION=geoip
GEOIP_API_KEY=your-iplocate-api-key
```

Add a dedicated Redis connection in `config/database.php`:

```php
'redis' => [
    // ... existing connections ...

    'geoip' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_GEOIP_DB', '3'),
        // REQUIRED: Lua function parses members as plain strings.
        // Serialization/compression would produce binary data Lua can't read.
        'serializer' => 0,  // Redis::SERIALIZER_NONE
        'compression' => 0, // Redis::COMPRESSION_NONE
    ],
],
```

> **Why `SERIALIZER_NONE`?** The Lua function reads Sorted Set members as plain strings (`"RU:3232235520"`) and splits on `:` via `string.find()`. If phpredis applies igbinary serialization or zstd compression, the member becomes binary data that Lua cannot parse.

## Usage

### Loading data

```bash
# Download CSV and load into Redis (auto-detects first run)
php artisan geoip:update

# Force update even if interval hasn't elapsed
php artisan geoip:update --force

# Check current status
php artisan geoip:update --status

# Only register/update the Lua function (no download)
php artisan geoip:update --register-function
```

On first run (no data in Redis), `geoip:update` automatically downloads and loads — no `--force` needed.

### Lookup

```php
use Mpanius\GeoIpRedis\Facades\GeoIpRedis;

GeoIpRedis::lookup('8.8.8.8');     // "US"
GeoIpRedis::lookup('77.88.55.77'); // "RU"
GeoIpRedis::lookup('invalid');     // null
```

Via dependency injection:

```php
use Mpanius\GeoIpRedis\GeoIpRedis;

class GeoController
{
    public function index(GeoIpRedis $geoip)
    {
        $country = $geoip->lookup(request()->ip());
    }
}
```

### Scheduling

```php
// routes/console.php
Schedule::command('geoip:update')->dailyAt('03:00');
```

## How it works

```
CSV (iplocate.io)
  → parse CIDR to integer ranges
    → pipeline ZADD into Redis Sorted Set
      → Lua Function registered via FUNCTION LOAD
        → FCALL_RO at request time → "RU"
```

### Redis data structure

**Sorted Set** (`geoip:v4`):
- **Score**: `end_ip` (unsigned 32-bit integer from CIDR)
- **Member**: `"CC:start_ip"` (e.g. `"RU:3232235520"`)

### Lookup algorithm (runs entirely in Redis)

```lua
ZRANGEBYSCORE geoip:v4 <ip_integer> +inf LIMIT 0 1
-- → finds the first range where end_ip >= query_ip
-- → verifies start_ip <= query_ip
-- → returns country code or false
```

Complexity: **O(log N)** per lookup.

### Why FCALL_RO over EVAL/EVALSHA

| | EVAL / EVALSHA | FCALL_RO |
|---|---|---|
| **Persistent** | No (lost on restart) | Yes (saved in RDB/AOF) |
| **Read replicas** | No | Yes (`no-writes` flag) |
| **Registration** | Per-connection | Once, globally |
| **phpredis** | `eval()` | `fcall_ro()` native method |

### Zero-downtime updates

Data is loaded into a temporary key (`geoip:v4:loading`), then atomically swapped via `RENAME`. Lookups never see partially loaded data.

## Data source

Default: [iplocate.io](https://www.iplocate.io) IP-to-Country CSV
- ~2M entries, ~8 MB download
- Updated daily
- License: CC BY-SA 4.0
- Format: `network,country,country_code,continent_code`

## Trusted Proxies

For `request()->ip()` to return the real client IP (not a load balancer or CDN address), you **must** configure trusted proxies in your Laravel application.

In `bootstrap/app.php` (Laravel 11+):

```php
->withMiddleware(function (Middleware $middleware) {
    // Trust all proxies (typical for apps behind a known LB/CDN)
    $middleware->trustProxies(at: '*');

    // Or trust specific proxy IPs
    $middleware->trustProxies(at: [
        '192.168.1.0/24',
        '10.0.0.0/8',
    ]);
})
```

**Without this configuration**, `request()->ip()` returns the proxy IP (e.g. `10.0.0.1`), and GeoIP lookup will return the wrong country or `null`.

Common setups:
- **Behind nginx/HAProxy** — trust internal network IPs
- **Behind Cloudflare** — trust [Cloudflare IP ranges](https://www.cloudflare.com/ips/) or use `at: '*'`
- **Behind AWS ALB/ELB** — use `at: '*'` (ALB forwards `X-Forwarded-For`)

> **Tip**: If you already have GeoIP headers from your edge layer (nginx `geoip2` module → `GEOIP_COUNTRY_CODE`, Cloudflare → `CF-IPCountry`), you can use those directly and skip the lookup. This package is ideal as a fallback or standalone solution when edge-level GeoIP is unavailable.

## Recommendations

- **Dedicated Redis DB**: Use a separate Redis database (e.g. DB 3) to isolate GeoIP data from application cache and sessions.
- **Schedule updates**: Run `geoip:update` daily via scheduler to keep data fresh. The command is idempotent — it skips the download if the configured interval hasn't elapsed.
- **Use as a fallback**: In most production setups, edge-level GeoIP (nginx/Cloudflare) is faster. Use this package as a fallback when headers are missing:
  ```php
  $country = request()->server('GEOIP_COUNTRY_CODE')
      ?: request()->header('CF-IPCountry')
      ?: GeoIpRedis::lookup(request()->ip())
      ?: 'US';
  ```
- **Read replicas**: Since the Lua function is registered with `flags = {'no-writes'}`, lookups via `FCALL_RO` work on Redis read replicas — ideal for scaling reads.
- **Monitor memory**: Check Redis memory usage with `geoip:update --status`. IPv4-only data typically uses 50–100 MB.
- **Verify after deploy**: After deploying to a new server, run `geoip:update --register-function` to ensure the Lua function is loaded (it persists in RDB/AOF, but a fresh Redis instance won't have it).

## Config reference

| Key | Env | Default | Description |
|-----|-----|---------|-------------|
| `connection` | `GEOIP_REDIS_CONNECTION` | `default` | Redis connection name |
| `key` | `GEOIP_REDIS_KEY` | `geoip:v4` | Sorted Set key |
| `download_url` | `GEOIP_DOWNLOAD_URL` | iplocate.io CSV | CSV download URL |
| `api_key` | `GEOIP_API_KEY` | — | API key for download service |
| `variant` | `GEOIP_VARIANT` | `daily` | Download variant |
| `update_interval_hours` | `GEOIP_UPDATE_INTERVAL` | `24` | Min hours between updates |
| `ipv6_enabled` | `GEOIP_IPV6_ENABLED` | `false` | Process IPv6 CIDR entries |
| `pipeline_batch_size` | — | `5000` | Batch size for pipeline ZADD |
| `function_library` | — | `geoip` | Redis Function library name |
| `function_name` | — | `geoip_country` | Redis Function name |
| `meta_key` | — | `geoip:meta` | Metadata hash key |

## License

MIT
