<?php

namespace Exceedone\Exment\Services\PluginDistribution;

use Composer\Semver\Comparator;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\Plugin\PluginInstaller;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The free official plugins bundled with Exment (ISS00419): ExmentPM, IT
 * Support, Groupware. They come with the package
 * exceedone/exment-plugins-release (also in the zip of Exment for the sites
 * that are not on the internet), as signed releases:
 *
 *   releases/<name>-<version>.zip and releases/<name>-<version>.json (ReleaseVerifier)
 *
 * Nothing of them is installed, loaded or made (no tables) until a system
 * administrator chooses "use" (Admin setting → Feature management); then the
 * release is checked again and installed as an uploaded plugin is. Nothing is
 * installed or updated by itself: a newer bundled version is only shown, and
 * the version before an update is kept, to go back to it.
 *
 * The package follows the minor versions of Exment: Exment 7.2 requires
 * exceedone/exment-plugins-release ~7.2.0 (its releases fit 7.2).
 *
 * A site run by its provider (a SaaS) may keep the versions to itself:
 * EXMENT_BUNDLED_PLUGINS_UPDATES=false hides "use this version" and "back to
 * the version before" (installing stays).
 *
 * A plugin installed by hand before (the same uuid) is the same plugin: its
 * settings, menus and permissions stay, and it is updated in place when the
 * administrator chooses so. A plugin of the same name and another uuid is
 * another plugin: it is not touched.
 */
class BundledPlugins
{
    public const PACKAGE = 'exceedone/exment-plugins-release';

    /** The versions kept to go back to (on the admin disk). */
    public const PREVIOUS_DIR = 'plugin_distribution/previous';

    public const STATUS_NOT_INSTALLED = 'not_installed';
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_UPDATE = 'update';
    /** The plugin installed is newer than the one bundled (by hand). */
    public const STATUS_NEWER = 'newer';

    /**
     * The folder of the releases (null: the package is not there).
     */
    public static function path(): ?string
    {
        $path = config('exment.bundled_plugins_path');
        if (!is_string($path) || $path === '') {
            $path = null;
            try {
                if (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled(static::PACKAGE)) {
                    $root = \Composer\InstalledVersions::getInstallPath(static::PACKAGE);
                    $path = isset($root) ? $root . '/releases' : null;
                }
            } catch (\Throwable $e) {
                $path = null;
            }
        }
        return isset($path) && is_dir($path) ? rtrim($path, '/\\') : null;
    }

    /**
     * The releases bundled that can be trusted and fit the site: for each
     * plugin (uuid), the newest one.
     *
     * @return array<string, array{record: array<string, mixed>, zip: string, json: string}>
     */
    public static function releases(): array
    {
        $dir = static::path();
        if (!isset($dir)) {
            return [];
        }
        $found = [];
        foreach (glob($dir . '/*.json') ?: [] as $json) {
            $zip = substr($json, 0, -5) . '.zip';
            $record = json_decode(strval(@file_get_contents($json)), true);
            if (!is_array($record)) {
                continue;
            }
            try {
                ReleaseVerifier::verify($record, $zip);
            } catch (\Throwable $e) {
                \Log::warning('[Exment] a bundled plugin is not used: ' . basename($json) . ': ' . $e->getMessage());
                continue;
            }
            $uuid = strval($record['uuid']);
            if (!isset($found[$uuid]) || Comparator::greaterThan(strval($record['version']), strval($found[$uuid]['record']['version']))) {
                $found[$uuid] = ['record' => $record, 'zip' => $zip, 'json' => $json];
            }
        }
        return $found;
    }

    /**
     * The rows of Feature management: each bundled plugin, and what the site has of it.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rows(): array
    {
        $rows = [];
        foreach (static::releases() as $uuid => $release) {
            $record = $release['record'];
            $config = ReleaseVerifier::configOfZip($release['zip']);
            $plugin = static::installed($uuid);
            $installed = isset($plugin) ? strval($plugin->version) : null;
            $status = static::STATUS_NOT_INSTALLED;
            if (isset($installed)) {
                $status = static::STATUS_INSTALLED;
                if (static::newer(strval($record['version']), $installed)) {
                    $status = static::STATUS_UPDATE;
                } elseif (static::newer($installed, strval($record['version']))) {
                    $status = static::STATUS_NEWER;
                }
            }
            // a fix for the safety the site should take (signed: security_min, ISS00421 "5")
            $securityMin = $record['security_min'] ?? (!empty($record['security']) ? $record['version'] : null);
            $security = isset($installed) && is_string($securityMin) && $securityMin !== '' && static::newer($securityMin, $installed);
            $previous = isset($installed) ? static::previousVersion($uuid, $installed) : null;
            $rows[] = [
                'uuid' => $uuid,
                'security' => $security,
                'security_note' => $security && is_string($record['security_note'] ?? null) ? mb_substr($record['security_note'], 0, 500) : null,
                'name' => strval($record['name']),
                'label' => strval($config['plugin_view_name'] ?? $record['name']),
                'description' => strval($config['description'] ?? ''),
                'version' => strval($record['version']),
                'installed_version' => $installed,
                'active' => isset($plugin) && boolval($plugin->active_flg),
                'status' => $status,
                'previous' => $previous,
                // the version before is older than the first safe one: going back takes the fix away
                'previous_unsafe' => isset($previous) && is_string($securityMin) && $securityMin !== '' && static::newer($securityMin, $previous),
                // installed by hand (not from the bundle): an update replaces what was put in by hand
                'by_hand' => isset($installed) && !static::disk()->exists(static::PREVIOUS_DIR . '/' . $uuid . '/' . $installed . '.json'),
                'plugin_id' => isset($plugin) ? $plugin->id : null,
            ];
        }
        usort($rows, function ($a, $b) {
            return strcmp($a['label'], $b['label']);
        });
        return $rows;
    }

    /**
     * The plugin of the uuid installed on the site (active or not).
     */
    public static function installed(string $uuid): ?Plugin
    {
        return Plugin::query()->where('uuid', $uuid)->first();
    }

    /**
     * Whether "use this version" and "back to the version before" are shown
     * (a SaaS may keep the versions to itself: EXMENT_BUNDLED_PLUGINS_UPDATES=false).
     */
    public static function updatesAllowed(): bool
    {
        return boolval(config('exment.bundled_plugins_updates', true));
    }

    protected static function newer(string $a, string $b): bool
    {
        try {
            return Comparator::greaterThan($a, $b);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Install the bundled release of a plugin, or update it to it (the
     * administrator chose it). The version before is kept.
     *
     * @throws ReleaseException|\RuntimeException
     */
    public static function install(string $uuid): Plugin
    {
        $release = static::releases()[$uuid] ?? null;
        if (!isset($release)) {
            throw new ReleaseException('not_found', $uuid);
        }
        $plugin = static::installed($uuid);
        if (isset($plugin) && !static::newer(strval($release['record']['version']), strval($plugin->version))) {
            // the same version or newer is there: nothing to do
            return $plugin;
        }
        return static::installRelease($release['record'], $release['zip']);
    }

    /**
     * Go back to the version kept before the last update.
     *
     * @throws ReleaseException|\RuntimeException
     */
    public static function rollback(string $uuid): Plugin
    {
        $plugin = static::installed($uuid);
        $previous = isset($plugin) ? static::previousVersion($uuid, strval($plugin->version)) : null;
        if (!isset($plugin) || !isset($previous)) {
            throw new ReleaseException('no_previous', $uuid);
        }
        $disk = static::disk();
        $base = static::PREVIOUS_DIR . '/' . $uuid . '/' . $previous;
        $record = json_decode(strval($disk->get($base . '.json')), true);
        if (!is_array($record)) {
            throw new ReleaseException('no_previous', $uuid);
        }
        $local = tempnam(sys_get_temp_dir(), 'exment_plugin_');
        if ($local === false || file_put_contents($local, strval($disk->get($base . '.zip'))) === false) {
            throw new ReleaseException('no_previous', $uuid);
        }
        try {
            $plugin = static::installRelease($record, $local, true);
        } finally {
            @unlink($local);
        }
        return $plugin;
    }

    /**
     * The newest version kept that is older than the installed one.
     */
    public static function previousVersion(string $uuid, string $installed): ?string
    {
        $disk = static::disk();
        $best = null;
        foreach ($disk->files(static::PREVIOUS_DIR . '/' . $uuid) as $file) {
            if (!str_ends_with($file, '.json')) {
                continue;
            }
            $version = basename($file, '.json');
            if (static::newer($installed, $version) && (!isset($best) || static::newer($version, $best))) {
                $best = $version;
            }
        }
        return $best;
    }

    /**
     * @param array<string, mixed> $record
     */
    protected static function installRelease(array $record, string $zip, bool $rollback = false): Plugin
    {
        // checked again, now (the files may have been changed since they were listed)
        ReleaseVerifier::verify($record, $zip);
        $uuid = strval($record['uuid']);
        $before = static::installed($uuid);
        // a plugin of the same name and another uuid is another plugin
        $sameName = Plugin::query()->where('plugin_name', strval($record['name']))->first();
        if (!isset($before) && isset($sameName)) {
            throw new ReleaseException('same_name', strval($record['name']));
        }

        $local = static::tempCopy($zip);
        session()->forget('errorMess');
        try {
            PluginInstaller::uploadPlugin(new UploadedFile($local, basename($zip), 'application/zip', null, true));
        } finally {
            @unlink($local);
        }
        $error = session()->pull('errorMess');
        \Exceedone\Exment\Model\System::clearCache();
        \Exceedone\Exment\Model\System::clearRequestSession();
        $plugin = static::installed($uuid);
        if (isset($error) || !isset($plugin) || strval($plugin->version) !== strval($record['version'])) {
            throw new \RuntimeException(strval($error ?: exmtrans('plugin_distribution.error_install')));
        }
        if (!isset($before) && !boolval($plugin->active_flg)) {
            $plugin->active_flg = true;
            $plugin->save();
        }
        if (!isset($before)) {
            static::turnOnItsFeatures($plugin);
        }

        // keep this version, to go back to it after a later update
        $disk = static::disk();
        $base = static::PREVIOUS_DIR . '/' . $uuid . '/' . strval($record['version']);
        if (!$disk->exists($base . '.json')) {
            $disk->put($base . '.zip', strval(file_get_contents($zip)));
            $disk->put($base . '.json', strval(json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        }
        return $plugin;
    }

    /**
     * "Use" of a plugin: its main features (registered with "existing") are
     * turned on too, so that one press starts it. Those an administrator has
     * decided on already stay as they are.
     */
    protected static function turnOnItsFeatures(Plugin $plugin): void
    {
        try {
            // the plugin registers its features when its class is made
            foreach (\Exceedone\Exment\Enums\PluginType::PLUGIN_TYPE_PUBLIC_CLASS() as $type) {
                if ($plugin->matchPluginType($type)) {
                    $plugin->getClass($type, ['throw_ex' => false]);
                }
            }
            $values = [];
            foreach (\Exceedone\Exment\Services\Features::definitions() as $key => $definition) {
                $key = strval($key);
                if (!$definition['existing'] || !\Exceedone\Exment\Services\Features::isNew($key)) {
                    continue;
                }
                $owner = \Exceedone\Exment\Services\Features::pluginOf($key);
                if (isset($owner) && $owner->id === $plugin->id) {
                    $values[$key] = ['enabled' => true, 'role_groups' => null, 'menu' => true];
                }
            }
            if (count($values) > 0) {
                \Exceedone\Exment\Services\Features::save($values);
                \Exceedone\Exment\Model\System::clearCache();
            }
        } catch (\Throwable $e) {
            \Log::warning('[Exment] the features of the plugin ' . $plugin->plugin_name . ': ' . $e->getMessage());
        }
    }

    protected static function tempCopy(string $zip): string
    {
        $local = tempnam(sys_get_temp_dir(), 'exment_plugin_');
        if ($local === false || !copy($zip, $local)) {
            throw new \RuntimeException(strval(exmtrans('plugin_distribution.error_install')));
        }
        return $local;
    }

    /**
     * @return \Illuminate\Contracts\Filesystem\Filesystem
     */
    protected static function disk()
    {
        return Storage::disk(Define::DISKNAME_ADMIN);
    }
}
