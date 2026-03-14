# laravel-geoip-redis

Native Redis GeoIP lookup для Laravel. Использует Redis 7+ Functions (`FCALL_RO`) и Sorted Sets для определения страны по IP-адресу.

**Весь lookup происходит внутри Redis** — PHP только отправляет команду и получает двухбуквенный код страны. Никакого парсинга на стороне PHP.

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
```

Redis connection в `config/database.php`:
```php
'geoip' => [
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'password' => env('REDIS_PASSWORD'),
    'port' => env('REDIS_PORT', '6379'),
    'database' => env('REDIS_GEOIP_DB', '3'),
    // ОБЯЗАТЕЛЬНО: Lua function парсит member как plain string ("CC:start_ip").
    // Сериализация/компрессия превратит данные в бинарный формат, который Lua не сможет прочитать.
    'serializer' => 0,  // Redis::SERIALIZER_NONE
    'compression' => 0, // Redis::COMPRESSION_NONE
],
```

> **Почему `SERIALIZER_NONE`?** Lua function внутри Redis читает member Sorted Set как plain string (`"RU:3232235520"`) и разделяет по `:` через `string.find()`. Если phpredis применит igbinary сериализацию или zstd компрессию, member превратится в бинарные данные, которые Lua не сможет распарсить.

## Использование

### Загрузка данных

```bash
# Скачать CSV и загрузить в Redis (первый запуск определяется автоматически)
php artisan geoip:update

# Принудительно обновить даже если интервал не истёк
php artisan geoip:update --force

# Показать текущий статус
php artisan geoip:update --status

# Только зарегистрировать/обновить Lua function (без скачивания)
php artisan geoip:update --register-function
```

При первом запуске (нет данных в Redis) `geoip:update` автоматически скачивает и загружает — `--force` не нужен.

### Lookup

```php
use Mpanius\GeoIpRedis\Facades\GeoIpRedis;

GeoIpRedis::lookup('8.8.8.8');     // "US"
GeoIpRedis::lookup('77.88.55.77'); // "RU"
GeoIpRedis::lookup('invalid');     // null
```

Через DI:

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

### Scheduler

```php
// routes/console.php
Schedule::command('geoip:update')->dailyAt('03:00');
```

## Как это работает

```
CSV (iplocate.io)
  → парсинг CIDR в integer-диапазоны
    → pipeline ZADD в Redis Sorted Set
      → Lua Function регистрируется через FUNCTION LOAD
        → FCALL_RO при запросе → "RU"
```

### Структура данных в Redis

**Sorted Set** (`geoip:v4`):
- **Score**: `end_ip` (unsigned 32-bit integer из CIDR)
- **Member**: `"CC:start_ip"` (например `"RU:3232235520"`)

### Алгоритм lookup (выполняется целиком в Redis)

```lua
ZRANGEBYSCORE geoip:v4 <ip_integer> +inf LIMIT 0 1
-- → находит первый диапазон где end_ip >= query_ip
-- → проверяет start_ip <= query_ip
-- → возвращает код страны или false
```

Сложность: **O(log N)** на один lookup.

### Почему FCALL_RO, а не EVAL/EVALSHA

| | EVAL / EVALSHA | FCALL_RO |
|---|---|---|
| **Persistent** | Нет (теряется при рестарте) | Да (RDB/AOF) |
| **Read replicas** | Нет | Да (флаг `no-writes`) |
| **Регистрация** | Per-connection | Один раз, глобально |
| **phpredis** | `eval()` | `fcall_ro()` нативный метод |

### Zero-downtime обновление

Данные загружаются во временный ключ (`geoip:v4:loading`), затем атомарно подменяются через `RENAME`. Lookup никогда не видит частично загруженные данные.

## Источник данных

По умолчанию — [iplocate.io](https://www.iplocate.io) IP-to-Country CSV:
- ~2M записей, ~8 MB
- Обновляется ежедневно
- Лицензия: CC BY-SA 4.0
- Формат: `network,country,country_code,continent_code`

## Лицензия

MIT
