<?php

declare(strict_types=1);

namespace Mpanius\GeoIpRedis\Support;

use RuntimeException;

class CsvDownloader
{
    /**
     * Download CSV file to a temporary path.
     *
     * @return string Path to the downloaded temp file
     *
     * @throws RuntimeException
     */
    public static function download(string $url, string $apiKey = '', string $variant = 'daily'): string
    {
        $downloadUrl = $url;

        // Append query parameters
        $params = [];
        if ($apiKey !== '') {
            $params['apikey'] = $apiKey;
        }
        if ($variant !== '') {
            $params['variant'] = $variant;
        }

        if ($params !== []) {
            $separator = str_contains($downloadUrl, '?') ? '&' : '?';
            $downloadUrl .= $separator . http_build_query($params);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'geoip_');
        if ($tempPath === false) {
            throw new RuntimeException('Failed to create temp file for GeoIP download.');
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 120,
                'user_agent' => 'laravel-geoip-redis/1.0',
            ],
        ]);

        $source = @fopen($downloadUrl, 'r', false, $context);
        if ($source === false) {
            @unlink($tempPath);
            throw new RuntimeException("Failed to download GeoIP CSV from: {$url}");
        }

        $dest = fopen($tempPath, 'w');
        if ($dest === false) {
            fclose($source);
            @unlink($tempPath);
            throw new RuntimeException("Failed to open temp file for writing: {$tempPath}");
        }

        $bytes = stream_copy_to_stream($source, $dest);
        fclose($source);
        fclose($dest);

        if ($bytes === false || $bytes === 0) {
            @unlink($tempPath);
            throw new RuntimeException('GeoIP CSV download resulted in empty file.');
        }

        return $tempPath;
    }
}
