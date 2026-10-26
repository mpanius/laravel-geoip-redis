<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Redis Connection
    |--------------------------------------------------------------------------
    |
    | Redis connection name from config/database.php.
    |
    | IMPORTANT: The connection MUST use serializer=NONE and compression=NONE.
    | The Lua function parses Sorted Set members as plain strings ("CC:start_ip")
    | via string.find(). Igbinary/PHP serialization or compression would turn
    | members into binary data that Lua cannot parse.
    |
    */
    'connection' => env('GEOIP_REDIS_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Redis Key
    |--------------------------------------------------------------------------
    |
    | Ключ Sorted Set для хранения IP-диапазонов.
    | Score = end_ip (uint32), Member = "CC:start_ip".
    |
    */
    'key' => env('GEOIP_REDIS_KEY', 'geoip:v4'),

    /*
    |--------------------------------------------------------------------------
    | Redis Key (IPv6)
    |--------------------------------------------------------------------------
    |
    | Ключ Sorted Set для IPv6-диапазонов. Все score = 0, порядок задаёт сам
    | member: "<end_hex32>:<start_hex32>:CC" (ZRANGEBYLEX). Создаётся только
    | при ipv6_enabled=true.
    |
    */
    'key_v6' => env('GEOIP_REDIS_KEY_V6', 'geoip:v6'),

    /*
    |--------------------------------------------------------------------------
    | Download URL
    |--------------------------------------------------------------------------
    |
    | URL for downloading the ip-to-country database.
    | Default: iplocate.io (CC BY-SA 4.0, daily updates).
    | iplocate.io returns a ZIP containing CSV.
    | CSV columns: network, continent_code, country_code, country_name
    | Columns are resolved by header name, not position.
    | Both plain CSV and ZIP-compressed CSV are supported automatically.
    |
    */
    'download_url' => env('GEOIP_DOWNLOAD_URL', 'https://www.iplocate.io/download/ip-to-country.csv'),

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    |
    | API key для сервиса скачивания (iplocate.io).
    |
    */
    'api_key' => env('GEOIP_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Download Variant
    |--------------------------------------------------------------------------
    |
    | Вариант скачивания: 'daily' или 'weekly'.
    |
    */
    'variant' => env('GEOIP_VARIANT', 'daily'),

    /*
    |--------------------------------------------------------------------------
    | Source: Laravel Storage disk
    |--------------------------------------------------------------------------
    |
    | Если source_disk задан (имя диска из config/filesystems.php, например s3),
    | geoip:update читает CSV/ZIP с этого диска по пути source_path вместо
    | скачивания по download_url; api_key и variant не используются.
    | null (по умолчанию) = прежнее поведение, скачивание по URL.
    | Повторная загрузка - только если файл на диске новее загруженного
    | (lastModified/размер в meta), иначе по update_interval_hours.
    |
    */
    'source_disk' => env('GEOIP_SOURCE_DISK'),

    'source_path' => env('GEOIP_SOURCE_PATH', 'geoip/ip-to-country.csv'),

    /*
    |--------------------------------------------------------------------------
    | Update Interval
    |--------------------------------------------------------------------------
    |
    | Минимальный интервал между обновлениями (в часах).
    | Команда geoip:update проверяет этот интервал перед скачиванием.
    |
    */
    'update_interval_hours' => (int) env('GEOIP_UPDATE_INTERVAL', 24),

    /*
    |--------------------------------------------------------------------------
    | IPv6 Support
    |--------------------------------------------------------------------------
    |
    | Загружать IPv6 CIDR-записи из CSV в отдельный ключ key_v6.
    | Lookup IPv6-адреса идёт в key_v6 независимо от флага (нет данных - null);
    | IPv4-mapped (::ffff:a.b.c.d) всегда разрешается как IPv4.
    |
    */
    'ipv6_enabled' => (bool) env('GEOIP_IPV6_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Pipeline Batch Size
    |--------------------------------------------------------------------------
    |
    | Размер batch для pipeline ZADD при загрузке данных в Redis.
    | Больше = быстрее загрузка, больше памяти.
    |
    */
    'pipeline_batch_size' => 5000,

    /*
    |--------------------------------------------------------------------------
    | Redis Function Library Name
    |--------------------------------------------------------------------------
    |
    | Имя Lua library в Redis 7+ (FUNCTION LOAD).
    | Содержит функцию lookup.
    |
    */
    'function_library' => 'geoip',

    /*
    |--------------------------------------------------------------------------
    | Redis Function Name
    |--------------------------------------------------------------------------
    |
    | Имя Lua function для FCALL_RO lookup.
    |
    */
    'function_name' => 'geoip_country',

    /*
    |--------------------------------------------------------------------------
    | Meta Key
    |--------------------------------------------------------------------------
    |
    | Redis Hash key для хранения метаданных (updated_at, entries_count и т.д.).
    |
    */
    'meta_key' => 'geoip:meta',
];
