# laravel-geoip-redis

Native Redis GeoIP lookup для Laravel. Использует Redis 7+ Functions (`FCALL_RO`) и Sorted Sets для определения страны по IP-адресу.

**Весь lookup происходит внутри Redis** — PHP только отправляет команду и получает двухбуквенный код страны.

## Требования

- PHP 8.2+
- Laravel 11/12
- Redis 7+ (для `FUNCTION LOAD` / `FCALL_RO`)
- ext-redis (phpredis)

## Установка

```bash
composer require mpanius/laravel-geoip-redis
```

Опционально опубликуйте конфиг:

```bash
php artisan vendor:publish --tag=geoip-redis-config
```

## Настройка

`.env`:
```env
GEOIP_REDIS_CONNECTION=geoip
GEOIP_API_KEY=your-iplocate-api-key
GEOIP_UPDATE_INTERVAL=24
```

Redis connection в `config/database.php`:
```php
'geoip' => [
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'password' => env('REDIS_PASSWORD'),
    'port' => env('REDIS_PORT', '6379'),
    'database' => env('REDIS_GEOIP_DB', '3'),
    'serializer' => 0,  // SERIALIZER_NONE
    'compression' => 0, // COMPRESSION_NONE
],
```

## Использование

### Загрузка данных

```bash
# Первая загрузка (скачать CSV + залить в Redis + зарегистрировать Lua function)
php artisan geoip:update --force

# Проверить статус
php artisan geoip:update --status

# Только зарегистрировать Lua function (без скачивания)
php artisan geoip:update --register-function
```

### Lookup

```php
use Mpanius\GeoIpRedis\Facades\GeoIpRedis;

$country = GeoIpRedis::lookup('8.8.8.8');     // "US"
$country = GeoIpRedis::lookup('77.88.55.77');  // "RU"
$country = GeoIpRedis::lookup('invalid');      // null
```

Или через DI:

```php
use Mpanius\GeoIpRedis\GeoIpRedis;

class MyController
{
    public function index(GeoIpRedis $geoip)
    {
        $country = $geoip->lookup(request()->ip());
    }
}
```

### Scheduler

```php
// app/Console/Kernel.php или routes/console.php
$schedule->command('geoip:update')->dailyAt('03:00');
```

## Как это работает

### Архитектура

```
CSV (iplocate.io) → parse CIDR → Redis Sorted Set → Lua Function → FCALL_RO
```

1. **Sorted Set** (`geoip:v4`): `score = end_ip`, `member = "CC:start_ip"`
2. **Lua Function** зарегистрирована через `FUNCTION LOAD` (persistent, survives restart)
3. **Lookup**: `FCALL_RO geoip_country 1 geoip:v4 <ip_as_integer>` → `"RU"` или `false`

### Почему FCALL_RO

| | EVAL/EVALSHA | FCALL_RO |
|---|---|---|
| Persistent | Нет | Да (RDB/AOF) |
| Read replicas | Нет | Да |
| phpredis | `eval()` | `fcall_ro()` нативно |

### Zero-downtime обновление

При `geoip:update` данные загружаются в temp key (`geoip:v4:loading`), затем атомарный `RENAME` на production key.

## Источник данных

По умолчанию — [iplocate.io](https://www.iplocate.io) IP-to-Country CSV:
- ~2M записей, ~8 MB
- Обновляется ежедневно
- Лицензия: CC BY-SA 4.0
- Формат: `network,country,country_code,continent_code`

## Лицензия

MIT
