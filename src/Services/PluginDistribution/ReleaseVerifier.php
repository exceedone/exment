<?php

namespace Exceedone\Exment\Services\PluginDistribution;

use Composer\Semver\Semver;

/**
 * The check of a release of a plugin (ISS00419 the plugins bundled with
 * Exment, ISS00420 the market, ISS00421 the rules of the signatures).
 *
 * A release is a zip and its record (a JSON next to it, or from the market):
 *
 * {"format": "exment-plugin-release/1", "uuid": "...", "name": "ExmentPM",
 *  "version": "1.2.0", "exment_requires": ">=7.1 <8.0", "php_requires": ">=8.2",
 *  "sha256": "<64 hex>", "size": 12345, "released_at": "2026-11-21T07:00:00Z",
 *  "key_id": "release-2026-1", "signature": "<base64>"}
 *
 * The signature is Ed25519, of the record without "signature", its keys
 * sorted, as JSON with no spaces (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).
 * The values are strings, integers, booleans or null; the fields not known
 * here are signed too, and pass. Whether a release was taken back (yanked)
 * is not in the record: it is in the catalog of the market.
 *
 * Only the keys below are trusted (a key in the settings or .env could be
 * put there by anyone who can change them). The keys of the tests are
 * trusted only while the tests run.
 */
class ReleaseVerifier
{
    public const FORMAT = 'exment-plugin-release/1';

    /** The role of the keys that sign the releases (key_id: release-<year>-<n>). */
    public const ROLE = 'release';

    /**
     * The public keys that sign the releases (key_id => base64 of the 32
     * bytes). Several, so that a key can be changed: a new one is added
     * before the old one is taken out.
     *
     * @var array<string, string>
     */
    public const TRUSTED_KEYS = [
        // the secret keys are only on the computer of the person who signs (ISS00419, ISS00430)
        'release-2026-1' => '1139S0QrS0q0a5azJfCqysobCHwWPWuADLhLdVJj49c=',
        // the spare: when the first one is lost, the releases are signed with it, without a new Exment
        'release-2026-2' => '8I+81EvIf3v/pNNFZFoUe65qOzociOpPgSPlDrgEIo4=',
    ];

    /**
     * The keys of the tests (test-release-<n>): only while the tests run.
     *
     * @var array<string, string>
     */
    protected static array $testKeys = [];

    /**
     * Trust a key in the tests (ignored out of the tests).
     */
    public static function trustForTests(string $keyId, string $publicKey): void
    {
        if (!app()->runningUnitTests() || !str_starts_with($keyId, 'test-' . static::ROLE . '-')) {
            return;
        }
        static::$testKeys[$keyId] = $publicKey;
    }

    public static function resetTestKeys(): void
    {
        static::$testKeys = [];
    }

    /**
     * The public key of a key_id, when it is trusted for the releases.
     */
    public static function publicKey(string $keyId): ?string
    {
        if (str_starts_with($keyId, 'test-')) {
            return app()->runningUnitTests() ? (static::$testKeys[$keyId] ?? null) : null;
        }
        if (!str_starts_with($keyId, static::ROLE . '-')) {
            return null;
        }
        return static::TRUSTED_KEYS[$keyId] ?? null;
    }

    /**
     * The text that is signed.
     *
     * @param array<string, mixed> $record
     */
    public static function canonical(array $record): string
    {
        unset($record['signature']);
        foreach ($record as $key => $value) {
            if (!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null) {
                throw new ReleaseException('value', strval($key));
            }
        }
        ksort($record, SORT_STRING);
        return json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Check the record: its form and its signature.
     *
     * @param array<string, mixed> $record
     * @throws ReleaseException
     */
    public static function verifyRecord(array $record): void
    {
        if (($record['format'] ?? null) !== static::FORMAT) {
            throw new ReleaseException('format');
        }
        foreach (['uuid', 'name', 'version', 'sha256', 'key_id', 'signature'] as $field) {
            if (!is_string($record[$field] ?? null) || $record[$field] === '') {
                throw new ReleaseException('field', $field);
            }
        }
        if (!is_int($record['size'] ?? null) || $record['size'] <= 0) {
            throw new ReleaseException('field', 'size');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $record['sha256']) !== 1) {
            throw new ReleaseException('field', 'sha256');
        }
        if ($record['uuid'] !== strtolower($record['uuid'])) {
            throw new ReleaseException('field', 'uuid');
        }
        // the fix for the safety (ISS00421 "5"), when it is there
        if (array_key_exists('security', $record) && !is_bool($record['security'])) {
            throw new ReleaseException('field', 'security');
        }
        foreach (['security_note', 'security_min'] as $field) {
            if (array_key_exists($field, $record) && $record[$field] !== null && !is_string($record[$field])) {
                throw new ReleaseException('field', $field);
            }
        }

        $key = static::publicKey($record['key_id']);
        if ($key === null) {
            throw new ReleaseException('key', $record['key_id']);
        }
        $publicKey = base64_decode($key, true);
        $signature = base64_decode($record['signature'], true);
        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new ReleaseException('signature');
        }
        if (!sodium_crypto_sign_verify_detached($signature, static::canonical($record), $publicKey)) {
            throw new ReleaseException('signature');
        }
    }

    /**
     * Whether the release fits the versions of Exment and PHP of the site.
     *
     * @param array<string, mixed> $record
     * @param string|null $exmentVersion null: the version of this Exment
     */
    public static function fits(array $record, ?string $exmentVersion = null): bool
    {
        $exmentVersion = $exmentVersion ?? static::exmentVersion();
        $exment = $record['exment_requires'] ?? null;
        // a version of the development ("dev-master") is not checked
        if (is_string($exment) && $exment !== '' && isset($exmentVersion) && preg_match('/\A\d/', ltrim($exmentVersion, 'v')) === 1) {
            if (!static::satisfies(ltrim($exmentVersion, 'v'), $exment)) {
                return false;
            }
        }
        $php = $record['php_requires'] ?? null;
        if (is_string($php) && $php !== '' && !static::satisfies(PHP_VERSION, $php)) {
            return false;
        }
        return true;
    }

    protected static function satisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function exmentVersion(): ?string
    {
        try {
            if (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('exceedone/exment')) {
                $version = \Composer\InstalledVersions::getPrettyVersion('exceedone/exment');
                if (is_string($version) && $version !== '') {
                    return $version;
                }
            }
        } catch (\Throwable $e) {
            // below
        }
        $version = \Exceedone\Exment\Facades\ExmentFacade::version(false);
        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * Check a release: its record, its zip (the size, the sha256, and the
     * uuid and the version of its config.json) and that it fits the site.
     *
     * @param array<string, mixed> $record
     * @throws ReleaseException
     */
    public static function verify(array $record, string $zipPath, ?string $exmentVersion = null): void
    {
        static::verifyRecord($record);
        if (!is_file($zipPath) || filesize($zipPath) !== $record['size']) {
            throw new ReleaseException('zip');
        }
        if (!hash_equals($record['sha256'], strval(hash_file('sha256', $zipPath)))) {
            throw new ReleaseException('zip');
        }
        $config = static::configOfZip($zipPath);
        if (strtolower(strval($config['uuid'] ?? '')) !== $record['uuid'] || strval($config['version'] ?? '') !== $record['version']) {
            throw new ReleaseException('config');
        }
        if (!static::fits($record, $exmentVersion)) {
            throw new ReleaseException('version');
        }
    }

    /**
     * The config.json of the plugin in a zip (the shallowest one).
     *
     * @return array<string, mixed>
     */
    public static function configOfZip(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ReleaseException('zip');
        }
        try {
            $found = null;
            $depth = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || basename($name) !== 'config.json') {
                    continue;
                }
                $d = substr_count(str_replace('\\', '/', $name), '/');
                if ($depth === null || $d < $depth) {
                    $depth = $d;
                    $found = $name;
                }
            }
            $json = isset($found) ? $zip->getFromName($found) : false;
        } finally {
            $zip->close();
        }
        $config = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($config)) {
            throw new ReleaseException('config');
        }
        return $config;
    }
}
