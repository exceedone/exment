<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Plugin;

/**
 * Where a record opens, when a plugin shows the records of a table on a
 * screen of its own (the project portal shows the issues of pm_issue).
 *
 * A notification, a mail and a LINE button then open that screen instead of
 * Exment's plain data page. With no plugin answering, the data page is used,
 * as before.
 *
 * A plugin answers in one of two ways:
 *
 * - RecordUrl::register('pm_issue', fn (CustomValue $value) => '...'), when
 *   its classes are made (its page, its event).
 * - A class {plugin namespace}\PluginRecordUrl with a static method
 *   recordUrl(CustomValue $value): ?string. Exment looks for it in the
 *   active page plugins, so it works also where the plugin was not made in
 *   this request (a mail sent from a batch). ExmentPM up to 1.7.4 has
 *   PortalDefinition::portalRecordUrl(), which is asked the same way.
 *
 * Only an address on this site is used (a path, or a URL with this site's
 * scheme, host and port). Anything else is ignored and logged, so a plugin
 * cannot send the reader to another site.
 */
class RecordUrl
{
    /**
     * @var array<string, array<int, callable>> table name => resolvers
     */
    protected static $resolvers = [];

    /**
     * The hook classes of the page plugins, looked up once per request.
     *
     * @var array<int, \Closure>|null
     */
    protected static $pluginHooks = null;

    /**
     * Say where the records of a table open.
     *
     * @param callable $resolver fn (CustomValue $value): ?string - a path or a URL of this site, or null
     */
    public static function register(string $table_name, callable $resolver): void
    {
        static::$resolvers[$table_name][] = $resolver;
    }

    /**
     * Forget the registered resolvers and the plugin hooks (for tests, and
     * after plugins were installed or removed).
     */
    public static function reset(): void
    {
        static::$resolvers = [];
        static::$pluginHooks = null;
    }

    /**
     * The address a plugin gives for this record, as a full URL of this site,
     * or null when no plugin answers.
     */
    public static function pluginUrl(?CustomValue $custom_value): ?string
    {
        if (!isset($custom_value) || !isset($custom_value->custom_table)) {
            return null;
        }
        $table_name = strval($custom_value->custom_table->table_name);

        foreach (static::$resolvers[$table_name] ?? [] as $resolver) {
            $url = static::ask($resolver, $custom_value);
            if (isset($url)) {
                return $url;
            }
        }

        foreach (static::pluginHooks() as $hook) {
            $url = static::ask($hook, $custom_value);
            if (isset($url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Where the record opens: the plugin's screen, else Exment's data page.
     */
    public static function url(CustomValue $custom_value): string
    {
        return static::pluginUrl($custom_value) ?? strval($custom_value->getUrl(['tag' => false, 'modal' => false]));
    }

    /**
     * $url as a full URL when it is an address on this site, else null.
     *
     * A path ("/admin/plugins/x#a") is made full with this site's root. A full
     * URL must have this site's scheme, host and port. "//host", a backslash,
     * a control character, or a scheme such as javascript: are refused.
     */
    public static function sameSite(mixed $url): ?string
    {
        if (!is_string($url)) {
            return null;
        }
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '/')) {
            if (str_starts_with($url, '//')) {
                return null;
            }
            $root = static::origin(url('/'));
            return isset($root) ? $root . $url : null;
        }

        $origin = static::origin($url);
        if (!isset($origin) || $origin !== static::origin(url('/'))) {
            return null;
        }
        return $url;
    }

    /**
     * scheme://host[:port] of a full http(s) URL, in lower case, the default
     * port left out; null for anything else.
     */
    protected static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $port = $parts['port'] ?? null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }
        return $scheme . '://' . strtolower($parts['host']) . (isset($port) ? ':' . $port : '');
    }

    /**
     * One resolver's answer, checked. A broken plugin must not stop the
     * notification or the mail.
     */
    protected static function ask(callable $resolver, CustomValue $custom_value): ?string
    {
        try {
            $answer = call_user_func($resolver, $custom_value);
        } catch (\Throwable $ex) {
            \Log::error($ex);
            return null;
        }
        if (is_null($answer)) {
            return null;
        }

        $url = static::sameSite($answer);
        if (!isset($url)) {
            \Log::warning('[Exment] a plugin gave an address of another site for a record; the data page is used.', [
                'table' => $custom_value->custom_table->table_name,
                'id' => $custom_value->id,
            ]);
        }
        return $url;
    }

    /**
     * The hook methods of the active page plugins.
     *
     * Plugin classes are not autoloaded until the plugin is required, so each
     * plugin is required before its classes are looked up.
     *
     * @return array<int, \Closure>
     */
    protected static function pluginHooks(): array
    {
        if (isset(static::$pluginHooks)) {
            return static::$pluginHooks;
        }

        $hooks = [];
        try {
            $plugins = Plugin::getByPluginTypes(PluginType::PAGE);
        } catch (\Throwable $ex) {
            \Log::error($ex);
            $plugins = [];
        }
        foreach ($plugins as $plugin) {
            try {
                $plugin->requirePlugin();
                $candidates = [
                    [$plugin->getNameSpace('PluginRecordUrl'), 'recordUrl'],
                    [$plugin->getNameSpace('PortalDefinition'), 'portalRecordUrl'],
                ];
                foreach ($candidates as [$class, $method]) {
                    if (class_exists($class) && is_callable([$class, $method])) {
                        $hooks[] = \Closure::fromCallable([$class, $method]);
                        break;
                    }
                }
            } catch (\Throwable $ex) {
                \Log::error($ex);
            }
        }

        return static::$pluginHooks = $hooks;
    }
}
