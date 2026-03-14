<?php

declare(strict_types=1);

namespace Mpanius\GeoIpRedis\Facades;

use Illuminate\Support\Facades\Facade;
use Mpanius\GeoIpRedis\GeoIpRedis as GeoIpRedisService;

/**
 * @method static string|null lookup(string $ip)
 * @method static \Mpanius\GeoIpRedis\UpdateResult update()
 * @method static void registerFunction()
 * @method static bool needsUpdate()
 * @method static array status()
 *
 * @see GeoIpRedisService
 */
class GeoIpRedis extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GeoIpRedisService::class;
    }
}
