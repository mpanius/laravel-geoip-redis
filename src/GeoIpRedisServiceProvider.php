<?php

declare(strict_types=1);

namespace Mpanius\GeoIpRedis;

use Mpanius\GeoIpRedis\Commands\GeoIpUpdateCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class GeoIpRedisServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('geoip-redis')
            ->hasConfigFile()
            ->hasCommand(GeoIpUpdateCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(GeoIpRedis::class);
    }
}
