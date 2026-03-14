# laravel-geoip-redis

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Native Redis GeoIP lookup for Laravel using Redis 7+ Functions (`FCALL_RO`) and Sorted Sets.

**The entire lookup happens inside Redis** — PHP only sends a command and receives a two-letter country code. Zero parsing at request time.

[README на русском](README_RU.md)

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Redis 7+ (for `FUNCTION LOAD` / `FCALL_RO`)
- ext-redis (phpredis)

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
