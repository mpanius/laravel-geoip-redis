<?php

declare(strict_types=1);

namespace Mpanius\GeoIpRedis\Support;

use RuntimeException;
use ZipArchive;

class CsvDownloader
{
    /**
     * Download CSV file to a temporary path.
     *
     * Handles both plain CSV and ZIP-compressed CSV (iplocate.io returns ZIP).
     * ZIP archives are automatically extracted — the first .csv file found is used.
     *
     * @return string Path to the CSV temp file (caller must unlink)
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
            throw new RuntimeException("Failed to download GeoIP data from: {$url}");
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
            throw new RuntimeException('GeoIP download resulted in empty file.');
        }

        // Detect ZIP and extract CSV
        if (self::isZipFile($tempPath)) {
            $csvPath = self::extractCsvFromZip($tempPath);
            @unlink($tempPath); // Remove the ZIP

            return $csvPath;
        }

        return $tempPath;
    }

    /**
     * Check if a file is a ZIP archive by reading its magic bytes.
     */
    protected static function isZipFile(string $path): bool
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return false;
        }

        $magic = fread($handle, 4);
        fclose($handle);

        // ZIP magic bytes: PK\x03\x04
        return $magic === "PK\x03\x04";
    }

    /**
     * Extract the first CSV file from a ZIP archive.
     *
     * @return string Path to the extracted CSV temp file
     *
     * @throws RuntimeException
     */
    protected static function extractCsvFromZip(string $zipPath): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ext-zip is required to process ZIP-compressed GeoIP downloads. Install it or use a plain CSV URL.');
        }

        $zip = new ZipArchive;
        $result = $zip->open($zipPath);
        if ($result !== true) {
            throw new RuntimeException("Failed to open ZIP archive: error code {$result}");
        }

        // Find the first .csv file in the archive
        $csvName = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && str_ends_with(strtolower($name), '.csv')) {
                $csvName = $name;
                break;
            }
        }

        if ($csvName === null) {
            $zip->close();
            throw new RuntimeException('ZIP archive does not contain a CSV file.');
        }

        // Extract CSV to a temp file
        $csvContent = $zip->getFromName($csvName);
        $zip->close();

        if ($csvContent === false) {
            throw new RuntimeException("Failed to read '{$csvName}' from ZIP archive.");
        }

        $csvPath = tempnam(sys_get_temp_dir(), 'geoip_csv_');
        if ($csvPath === false) {
            throw new RuntimeException('Failed to create temp file for extracted CSV.');
        }

        $written = file_put_contents($csvPath, $csvContent);
        if ($written === false) {
            @unlink($csvPath);
            throw new RuntimeException('Failed to write extracted CSV to temp file.');
        }

        return $csvPath;
    }
}
