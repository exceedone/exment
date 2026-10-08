<?php

namespace Exceedone\Exment\Services\GeoIp;

use Carbon\Carbon;
use MaxMind\Db\Reader;

/**
 * Resolve country and location from an IP address, using a local GeoIP database file (.mmdb).
 *
 * The database file is optional. If it is not placed, every lookup returns empty values,
 * so callers do not need to care whether GeoIP is available.
 * No request is sent outside the server when resolving an IP address.
 */
class GeoIpService
{
    /**
     * Default directory of the database file, relative to storage_path().
     */
    public const DEFAULT_DIRECTORY = 'app/geoip';

    /**
     * File name used when the database is downloaded to the default directory.
     */
    public const DEFAULT_FILENAME = 'dbip-city-lite.mmdb';

    /**
     * Get the database file path in use.
     * config "exment.geoip_db_path" wins. Otherwise the first *.mmdb file in the default directory.
     *
     * @return string|null null if the database file is not placed.
     */
    public static function getDatabasePath(): ?string
    {
        $path = config('exment.geoip_db_path');
        if (!is_nullorempty($path)) {
            return is_file($path) ? $path : null;
        }

        $files = glob(path_join(storage_path(static::DEFAULT_DIRECTORY), '*.mmdb')) ?: [];
        sort($files);

        return count($files) > 0 ? $files[0] : null;
    }

    /**
     * Get the path the downloaded database file is saved to.
     *
     * @return string
     */
    public static function getDownloadTargetPath(): string
    {
        $path = config('exment.geoip_db_path');
        if (!is_nullorempty($path)) {
            return $path;
        }

        return static::getDatabasePath() ?? path_join(storage_path(static::DEFAULT_DIRECTORY), static::DEFAULT_FILENAME);
    }

    /**
     * Whether GeoIP lookup is available.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return class_exists(Reader::class) && !is_null(static::getDatabasePath());
    }

    /**
     * Get database file information.
     *
     * @return array{type: string, build_date: Carbon}|null null if the database is not available or broken.
     */
    public static function getDatabaseInfo(): ?array
    {
        $reader = static::openReader();
        if (is_null($reader)) {
            return null;
        }

        try {
            $metadata = $reader->metadata();
            return [
                'type' => (string)$metadata->databaseType,
                'build_date' => Carbon::createFromTimestamp($metadata->buildEpoch),
            ];
        } catch (\Throwable $ex) {
            return null;
        } finally {
            static::closeReader($reader);
        }
    }

    /**
     * Resolve country and location from IP address.
     * Private address, unknown address, or the database is not placed: all values are null.
     *
     * @param string|null $ip
     * @return array{country_code: string|null, country: string|null, region: string|null, city: string|null}
     */
    public static function lookup(?string $ip): array
    {
        $result = [
            'country_code' => null,
            'country' => null,
            'region' => null,
            'city' => null,
        ];

        if (is_nullorempty($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $result;
        }

        $reader = static::openReader();
        if (is_null($reader)) {
            return $result;
        }

        try {
            $record = $reader->get($ip);
        } catch (\Throwable $ex) {
            \Log::warning('GeoIP lookup failed. ' . $ex->getMessage());
            return $result;
        } finally {
            static::closeReader($reader);
        }

        if (!is_array($record)) {
            return $result;
        }

        $country_code = array_get($record, 'country.iso_code');
        if (is_string($country_code) && strlen($country_code) == 2) {
            $result['country_code'] = strtoupper($country_code);
        }
        $result['country'] = static::getName(array_get($record, 'country.names'));
        $result['region'] = static::getName(array_get($record, 'subdivisions.0.names'));
        $result['city'] = static::getName(array_get($record, 'city.names'));

        return $result;
    }

    /**
     * Download the database file and replace the current one.
     * The current file is kept if download or validation fails.
     *
     * @param string|null $url download url. If null, use config "exment.geoip_download_url".
     * @return string saved file path
     * @throws \Throwable
     */
    public static function download(?string $url = null): string
    {
        if (!class_exists(Reader::class)) {
            throw new \RuntimeException('Package "maxmind-db/reader" is not installed. Please execute "composer require maxmind-db/reader".');
        }

        $target = static::getDownloadTargetPath();
        \File::ensureDirectoryExists(dirname($target));

        $tmpDownload = $target . '.download';
        $tmpDatabase = $target . '.tmp';

        try {
            static::downloadFile(is_nullorempty($url) ? static::getDownloadUrls() : [$url], $tmpDownload);

            if (static::isGzip($tmpDownload)) {
                static::gunzip($tmpDownload, $tmpDatabase);
            } else {
                \File::move($tmpDownload, $tmpDatabase);
            }

            static::validateDatabase($tmpDatabase);

            if (!@rename($tmpDatabase, $target)) {
                throw new \RuntimeException("Cannot replace GeoIP database file : {$target}");
            }
        } finally {
            foreach ([$tmpDownload, $tmpDatabase] as $tmp) {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }

        return $target;
    }

    /**
     * Get download urls. This month's file, and last month's one for the days before this month's is published.
     *
     * @param Carbon|null $now
     * @return array<string>
     */
    public static function getDownloadUrls(?Carbon $now = null): array
    {
        $format = config('exment.geoip_download_url');
        if (is_nullorempty($format)) {
            return [];
        }

        $now = $now ?? Carbon::now();
        $urls = [];
        foreach ([$now->copy()->startOfMonth(), $now->copy()->startOfMonth()->subMonth()] as $date) {
            $urls[] = str_replace(['{year}', '{month}'], [$date->format('Y'), $date->format('m')], $format);
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return Reader|null
     */
    protected static function openReader(): ?Reader
    {
        if (!class_exists(Reader::class)) {
            return null;
        }

        $path = static::getDatabasePath();
        if (is_null($path)) {
            return null;
        }

        try {
            return new Reader($path);
        } catch (\Throwable $ex) {
            \Log::warning("GeoIP database cannot be opened : {$path}. " . $ex->getMessage());
            return null;
        }
    }

    /**
     * @param Reader $reader
     * @return void
     */
    protected static function closeReader(Reader $reader): void
    {
        try {
            $reader->close();
        } catch (\Throwable $ex) {
        }
    }

    /**
     * Get name by locale. System locale first, then English.
     *
     * @param mixed $names
     * @return string|null
     */
    protected static function getName($names): ?string
    {
        if (!is_array($names)) {
            return null;
        }

        foreach ([config('app.locale'), 'en'] as $locale) {
            $name = array_get($names, $locale);
            if (is_string($name) && $name !== '') {
                return mb_substr($name, 0, 255);
            }
        }

        return null;
    }

    /**
     * Download to $path. Try urls in order until one succeeds.
     *
     * @param array<string> $urls
     * @param string $path
     * @return void
     */
    protected static function downloadFile(array $urls, string $path): void
    {
        if (count($urls) == 0) {
            throw new \RuntimeException('GeoIP database download url is not set. Please set config "exment.geoip_download_url".');
        }

        $errors = [];
        foreach ($urls as $url) {
            try {
                $response = (new \GuzzleHttp\Client())->request('GET', $url, [
                    'sink' => $path,
                    'http_errors' => false,
                    'connect_timeout' => 30,
                    'timeout' => 1800,
                ]);
            } catch (\Throwable $ex) {
                $errors[] = "{$url} : " . $ex->getMessage();
                continue;
            }

            if ($response->getStatusCode() == 200) {
                return;
            }
            $errors[] = "{$url} : HTTP " . $response->getStatusCode();
        }

        throw new \RuntimeException('GeoIP database download failed. ' . implode(' / ', $errors));
    }

    /**
     * @param string $path
     * @return bool
     */
    protected static function isGzip(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $magic = fread($handle, 2);
        fclose($handle);

        return $magic === "\x1f\x8b";
    }

    /**
     * @param string $source
     * @param string $destination
     * @return void
     */
    protected static function gunzip(string $source, string $destination): void
    {
        $in = gzopen($source, 'rb');
        if ($in === false) {
            throw new \RuntimeException("Cannot open downloaded file : {$source}");
        }
        $out = fopen($destination, 'wb');
        if ($out === false) {
            gzclose($in);
            throw new \RuntimeException("Cannot write file : {$destination}");
        }

        try {
            while (!gzeof($in)) {
                $buffer = gzread($in, 1024 * 1024);
                if ($buffer === false || fwrite($out, $buffer) === false) {
                    throw new \RuntimeException("Cannot extract downloaded file : {$source}");
                }
            }
        } finally {
            gzclose($in);
            fclose($out);
        }
    }

    /**
     * Check the file can be read as a GeoIP database.
     *
     * @param string $path
     * @return void
     */
    protected static function validateDatabase(string $path): void
    {
        try {
            $reader = new Reader($path);
            $reader->metadata();
            $reader->get('8.8.8.8');
            $reader->close();
        } catch (\Throwable $ex) {
            throw new \RuntimeException('Downloaded file is not a valid GeoIP database. ' . $ex->getMessage());
        }
    }
}
