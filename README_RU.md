# laravel-geoip-redis

Native Redis GeoIP lookup для Laravel. Использует Redis 7+ Functions (`FCALL_RO`) и Sorted Sets для определения страны по IP-адресу.

**Весь lookup происходит внутри Redis** — PHP только отправляет команду и получает двухбуквенный код страны. Никакого парсинга на стороне PHP.

## Зачем этот пакет?

Существует несколько способов определить страну по IP клиента. У каждого свои компромиссы:

| Подход | Где выполняется lookup | Задержка | Мульти-нода | Актуальность данных | Сложность настройки |
|---|---|---|---|---|---|
| **Этот пакет** | Redis (FCALL_RO) | <1 мс | Общий Redis, обновление один раз | Ежедневное авто-обновление CSV | Средняя |
| **nginx geoip2 module** | nginx (C, in-process) | ~0 мс | mmdb файл на каждой ноде | Ручная загрузка + reload | Высокая (модуль/пересборка) |
| **Cloudflare CF-IPCountry** | Edge Cloudflare | 0 мс (заголовок) | Автоматически | Всегда актуально | Низкая (переключатель) |
| **Torann/laravel-geoip** | PHP (MaxMind Reader) | <1 мс | mmdb файл на каждой ноде | Ручное или `geoip:update` | Средняя |
| **MaxMind GeoIP2 PHP** | PHP (бинарный поиск в mmdb) | <1 мс | mmdb файл на каждой ноде | Ручная загрузка | Средняя |
| **API-сервисы** (ipinfo, iplocate, ip-api) | Внешний HTTP | 50–200 мс | N/A | Всегда актуально | Низкая |

### Ключевые отличия

**vs nginx geoip2 module**
- nginx-модуль — самый быстрый (~0 мс, C-код в процессе), но требует `.mmdb` файл **на каждой ноде**, пересборку nginx или подключение динамического модуля, и ручной reload после обновления базы. Этот пакет загружает данные в общий Redis один раз — все ноды читают из него сразу, без reload nginx.

**vs Cloudflare CF-IPCountry**
- Cloudflare — zero-effort, если вы уже за CF. Но работает только когда трафик идёт через CF. Внутренний трафик, health checks, queue workers и окружения без CF — заголовка не получат. Этот пакет работает для любого IP, из любого контекста (HTTP, CLI, очереди).

**vs Torann/laravel-geoip (MaxMind)**
- laravel-geoip загружает `.mmdb` файл в память PHP-процесса и выполняет бинарный поиск в PHP. Каждый FPM/Octane воркер держит свою копию в RAM. Этот пакет выносит весь парсинг в Redis — PHP-воркеры остаются легковесными. С Octane/FrankenPHP (долгоживущие процессы) это предотвращает дублирование mmdb в памяти каждого воркера.

**vs API-сервисы**
- HTTP API-вызовы добавляют 50–200 мс задержки и требуют rate-limiting / кеширование. Этот пакет — одна Redis-команда (<1 мс) без внешних зависимостей в момент запроса.

### Мульти-нодовая архитектура

```
┌─────────────┐
│  Scheduler   │  php artisan geoip:update (запускается один раз, ежедневно)
│  (любая нода)│──────────────┐
└─────────────┘              │
                              ▼
                     ┌─────────────────┐
                     │   Общий Redis    │  Sorted Set + Lua Function
                     │   (shared host)  │  хранятся глобально в Redis
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

- **Обновление один раз**: `geoip:update` запускается на одной ноде (scheduler, cron или вручную). Данные записываются в общий Redis.
- **Чтение отовсюду**: Все ноды приложения вызывают `FCALL_RO` на тот же Redis. Lua function зарегистрирована в Redis глобально, не per-connection.
- **Синхронизация файлов не нужна**: В отличие от mmdb-решений, не нужно раскладывать файлы баз данных по нодам.
- **Read replicas**: Если у Redis есть реплики, `FCALL_RO` (с флагом `no-writes`) может выполняться на репликах, разгружая primary.

## Требования

- PHP 8.2+
- Laravel 11/12
- **Redis 7+** (для `FUNCTION LOAD` / `FCALL_RO`)
- **ext-redis** (phpredis) — Predis не поддерживается (нет нативного `fcall_ro()`)

### Обязательные условия

| Требование | Почему |
|---|---|
| **Redis 7+** | `FUNCTION LOAD` / `FCALL_RO` появились в Redis 7.0. Более ранние версии не поддерживают серверные функции. Проверьте: `redis-cli INFO server \| grep redis_version`. |
| **phpredis** (не Predis) | Пакет использует `fcall_ro()`, `function()` и `pipeline()` — нативные методы phpredis. Predis их не предоставляет. |
| **Выделенное Redis-соединение** | Обязательно `serializer=NONE` и `compression=NONE`. Подробности в разделе [Настройка](#настройка). |
| **Trusted Proxies** | `request()->ip()` должен возвращать реальный IP клиента, а не адрес балансировщика или CDN. См. [Trusted Proxies](#trusted-proxies). |
| **Сетевой доступ** | Сервер, на котором запускается `geoip:update`, должен иметь доступ к URL скачивания CSV. |

### Redis-совместимые альтернативы

Пакет требует команды `FUNCTION LOAD` и `FCALL_RO` (Redis 7.0+). Не все Redis-совместимые БД их поддерживают:

| База данных | Поддержка FCALL_RO | Примечания |
|---|---|---|
| **Redis** 7+ | Да | Полная поддержка. Эталонная реализация. |
| **Valkey** 7+ | Да | Форк Redis от Linux Foundation. Полная поддержка FCALL_RO с версии 7.0.0. |
| **Apache Kvrocks** 2.7+ | Да | Поддерживается с v2.7.0, включая выполнение на read-only репликах. |
| **Dragonfly** | **Нет** | Поддерживает только Lua-скриптинг (`EVAL`/`EVALSHA`). `FUNCTION LOAD` / `FCALL_RO` не реализованы. |
| **KeyDB** | **Нет** | Основан на кодовой базе Redis 6.x. Не поддерживает Redis 7 Functions. |
| **Amazon ElastiCache** | Зависит от версии | Поддерживается только на движке Redis 7+. Нужен Serverless или версия ≥ 7.0. |
| **Azure Cache for Redis** | Зависит от тарифа | Enterprise и Premium тарифы с движком Redis 7+. |
| **Google Memorystore** | Зависит от версии | Поддерживается при использовании движка Redis 7+. |

> **Если ваша Redis-альтернатива не поддерживает FCALL_RO**, пакет работать не будет. Fallback на EVAL/EVALSHA намеренно не реализован — FCALL_RO обеспечивает персистентность, поддержку read-реплик и лучшую производительность, которые EVAL дать не может.

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

## Trusted Proxies

Чтобы `request()->ip()` возвращал реальный IP клиента (а не адрес балансировщика или CDN), **необходимо** настроить trusted proxies.

В `bootstrap/app.php` (Laravel 11+):

```php
->withMiddleware(function (Middleware $middleware) {
    // Доверять всем прокси (типично для приложений за известным LB/CDN)
    $middleware->trustProxies(at: '*');

    // Или доверять конкретным IP прокси
    $middleware->trustProxies(at: [
        '192.168.1.0/24',
        '10.0.0.0/8',
    ]);
})
```

**Без этой настройки** `request()->ip()` вернёт IP прокси (например `10.0.0.1`), и GeoIP lookup определит неверную страну или вернёт `null`.

Типичные сценарии:
- **За nginx/HAProxy** — доверять IP внутренней сети
- **За Cloudflare** — доверять [IP-диапазонам Cloudflare](https://www.cloudflare.com/ips/) или использовать `at: '*'`
- **За AWS ALB/ELB** — использовать `at: '*'` (ALB передаёт `X-Forwarded-For`)

> **Совет**: Если у вас уже есть GeoIP-заголовки от edge-слоя (nginx `geoip2` module → `GEOIP_COUNTRY_CODE`, Cloudflare → `CF-IPCountry`), можно использовать их напрямую, минуя lookup. Этот пакет идеален как fallback или standalone-решение, когда edge-level GeoIP недоступен.

## Рекомендации

- **Выделенная Redis DB**: Используйте отдельную базу Redis (например DB 3) для изоляции GeoIP данных от кеша приложения и сессий.
- **Планировщик**: Запускайте `geoip:update` ежедневно через scheduler. Команда идемпотентна — пропускает скачивание, если настроенный интервал не истёк.
- **Используйте как fallback**: В большинстве production-окружений edge-level GeoIP (nginx/Cloudflare) быстрее. Используйте этот пакет как fallback при отсутствии заголовков:
  ```php
  $country = request()->server('GEOIP_COUNTRY_CODE')
      ?: request()->header('CF-IPCountry')
      ?: GeoIpRedis::lookup(request()->ip())
      ?: 'US';
  ```
- **Read replicas**: Lua function зарегистрирована с `flags = {'no-writes'}`, поэтому lookup через `FCALL_RO` работает на Redis read replicas — идеально для масштабирования чтения.
- **Мониторинг памяти**: Проверяйте использование памяти Redis командой `geoip:update --status`. Данные только по IPv4 обычно занимают 50–100 MB.
- **Проверка после деплоя**: После деплоя на новый сервер выполните `geoip:update --register-function`, чтобы убедиться что Lua function загружена (она сохраняется в RDB/AOF, но свежий экземпляр Redis её не содержит).

## Лицензия

MIT
