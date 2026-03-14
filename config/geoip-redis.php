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
    | Download URL
    |--------------------------------------------------------------------------
    |
    | URL для скачивания CSV базы ip-to-country.
    | По умолчанию — iplocate.io (CC BY-SA 4.0, daily updates).
    | Формат CSV: network,country,country_code,continent_code
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
    | Включить обработку IPv6 CIDR-записей из CSV.
    | По умолчанию — только IPv4 (достаточно для большинства задач).
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
