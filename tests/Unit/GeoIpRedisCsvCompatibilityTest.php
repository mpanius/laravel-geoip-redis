<?php

declare(strict_types=1);

namespace Mpanius\GeoIpRedis\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GeoIpRedisCsvCompatibilityTest extends TestCase
{
    #[Test]
    public function it_passes_explicit_escape_parameter_to_fgetcsv(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src/GeoIpRedis.php');

        $this->assertSame(
            2,
            substr_count($source, 'fgetcsv($handle, null, \',\', \'"\', \'\\\\\')'),
        );
    }
}
