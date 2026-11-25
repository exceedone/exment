<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\UserSetting as UserSettingKey;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\UserSetting;
use Illuminate\Http\Request;

/**
 * The language of a user (ISS02835).
 *
 * The language of a page: the language the user chose (user setting) → the language chosen on the
 * login page (cookie, before the login) → the browser's language (Accept-Language) → the language of
 * the site (APP_LOCALE). Only the languages the administrator allows (System "allowed_locales"; all
 * the installed languages when empty), always with the language of the site.
 * The installed languages are the folders of resources/lang: a new folder is a new language.
 */
class UserLocale
{
    /** the key of the user setting (user_settings.settings.user_setting.locale) */
    public const SETTING_KEY = UserSettingKey::USER_SETTING . '.locale';

    /** the cookie of the language chosen on the login page */
    public const COOKIE_NAME = 'exment_locale';

    /**
     * The installed languages: the folders of resources/lang (and EXMENT_SYSTEM_LOCALE_OPTIONS).
     *
     * @return array<int, string>
     */
    public static function installed(): array
    {
        $locales = [];
        $dir = __DIR__ . '/../../resources/lang';
        foreach ((array)glob($dir . '/*', GLOB_ONLYDIR) as $path) {
            $name = basename((string)$path);
            if (static::isLocaleName($name) && is_file($path . '/exment.php')) {
                $locales[] = $name;
            }
        }

        $extra = config('exment.system_locale_options');
        if (!is_nullorempty($extra)) {
            foreach (explode(',', (string)$extra) as $name) {
                $name = trim($name);
                // only a language that has the texts of Exment (a published resources/lang/<locale>/vendor file etc.)
                if (static::isLocaleName($name) && app('translator')->hasForLocale('exment::exment.label', $name)) {
                    $locales[] = $name;
                }
            }
        }

        // ja and en first, as before
        $locales = array_values(array_unique(array_merge(['ja', 'en'], $locales)));
        return $locales;
    }

    /**
     * The language of the site (APP_LOCALE).
     */
    public static function siteDefault(): string
    {
        return (string)(config('exment.locale') ?: config('app.fallback_locale') ?: 'en');
    }

    /**
     * The languages the users can choose. The language of the site always.
     *
     * @return array<int, string>
     */
    public static function allowed(): array
    {
        $installed = static::installed();
        $allowed = [];
        try {
            $value = System::allowed_locales();
            $allowed = is_array($value) ? $value : [];
        } catch (\Throwable $ex) {
            // before the installation (no database)
            $allowed = [];
        }
        $allowed = array_values(array_intersect($installed, $allowed));
        if (empty($allowed)) {
            $allowed = $installed;
        }
        $site = static::siteDefault();
        if (!in_array($site, $allowed, true)) {
            array_unshift($allowed, $site);
        }
        return $allowed;
    }

    /**
     * The languages the users can choose, with the name of each in its own language.
     *
     * @return array<string, string>
     */
    public static function allowedOptions(): array
    {
        return static::options(static::allowed());
    }

    /**
     * The names of the languages, each in its own language.
     *
     * @param array<int, string>|null $locales null: the installed languages
     * @return array<string, string>
     */
    public static function options(?array $locales = null): array
    {
        $options = [];
        foreach ($locales ?? static::installed() as $locale) {
            $options[$locale] = static::label($locale);
        }
        return $options;
    }

    /**
     * The name of a language in that language ("label" of its exment.php; else the name from intl; else the code).
     */
    public static function label(string $locale): string
    {
        $translator = app('translator');
        if ($translator->hasForLocale('exment::exment.label', $locale)) {
            return (string)$translator->get('exment::exment.label', [], $locale, false);
        }
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayName(str_replace('_', '-', $locale), str_replace('_', '-', $locale));
            if (is_string($name)) {
                return $name;
            }
        }
        return $locale;
    }

    /**
     * Find the allowed language for a name (ja, en-US, zh_CN, zh-cn ...), or null.
     *
     * @param array<int, string>|null $allowed null: the allowed languages
     */
    public static function match(?string $name, ?array $allowed = null): ?string
    {
        if (is_nullorempty($name)) {
            return null;
        }
        $allowed = $allowed ?? static::allowed();
        $normalized = static::normalize((string)$name);

        // the same name
        foreach ($allowed as $locale) {
            if (static::normalize($locale) === $normalized) {
                return $locale;
            }
        }
        // the same language (en-US → en, zh → zh-CN)
        $language = explode('-', $normalized)[0];
        foreach ($allowed as $locale) {
            if (static::normalize($locale) === $language) {
                return $locale;
            }
        }
        foreach ($allowed as $locale) {
            if (explode('-', static::normalize($locale))[0] === $language) {
                return $locale;
            }
        }
        return null;
    }

    /**
     * The best allowed language of an Accept-Language header, or null.
     *
     * @param array<int, string>|null $allowed null: the allowed languages
     */
    public static function fromAcceptLanguage(?string $header, ?array $allowed = null): ?string
    {
        if (is_nullorempty($header)) {
            return null;
        }
        $allowed = $allowed ?? static::allowed();

        $items = [];
        foreach (explode(',', (string)$header) as $index => $part) {
            $pieces = explode(';', trim($part));
            $name = trim($pieces[0]);
            if ($name === '' || $name === '*') {
                continue;
            }
            $quality = 1.0;
            foreach (array_slice($pieces, 1) as $piece) {
                $piece = trim($piece);
                if (strpos($piece, 'q=') === 0) {
                    $quality = (float)substr($piece, 2);
                }
            }
            if ($quality <= 0) {
                continue;
            }
            $items[] = [$name, $quality, $index];
        }
        // by the quality, then by the order
        usort($items, function ($a, $b) {
            return $b[1] <=> $a[1] ?: $a[2] <=> $b[2];
        });

        foreach ($items as $item) {
            if (!is_null($locale = static::match($item[0], $allowed))) {
                return $locale;
            }
        }
        return null;
    }

    /**
     * The language a user chose (user setting), when it is allowed, or null.
     *
     * @param mixed $user LoginUser, a user CustomValue, or the id of the user
     */
    public static function forUser($user): ?string
    {
        return static::match(static::userSetting($user, static::SETTING_KEY));
    }

    /**
     * The language of a request: user → cookie of the login page → Accept-Language → the site.
     *
     * @param mixed $user LoginUser, a user CustomValue, the id of the user or null
     */
    public static function resolve($user = null, ?Request $request = null): string
    {
        $allowed = static::allowed();

        if (!is_null($user) && !is_null($locale = static::match(static::userSetting($user, static::SETTING_KEY), $allowed))) {
            return $locale;
        }
        if (!is_null($request)) {
            $cookie = $request->cookie(static::COOKIE_NAME);
            if (is_string($cookie) && !is_null($locale = static::match($cookie, $allowed))) {
                return $locale;
            }
            if (static::useBrowserLanguage() && !is_null($locale = static::fromAcceptLanguage($request->header('Accept-Language'), $allowed))) {
                return $locale;
            }
        }
        return static::match(static::siteDefault(), $allowed) ?? static::siteDefault();
    }

    /**
     * Whether the language of the browser is used for a user who has not chosen one:
     * the system setting "locale_from_browser" (System → Advanced, on by default).
     * EXMENT_LOCALE_FROM_BROWSER (config exment.locale_from_browser), when set, wins over the setting.
     * In the tests it is off unless a test asks for it (config exment.locale_from_browser_in_tests):
     * their client sends "Accept-Language: en-us" with every request.
     */
    public static function useBrowserLanguage(): bool
    {
        $value = config('exment.locale_from_browser');
        if (!is_null($value) && $value !== '') {
            return boolval($value);
        }
        if (app()->runningUnitTests() && !boolval(config('exment.locale_from_browser_in_tests', false))) {
            return false;
        }
        try {
            return boolval(System::locale_from_browser());
        } catch (\Throwable $ex) {
            // before the installation (no database)
            return true;
        }
    }

    /**
     * Use a language for the page (translations, Carbon, the date pickers).
     */
    public static function apply(string $locale): void
    {
        \App::setLocale($locale);
        static::aliasAdminLang($locale);
        try {
            \Carbon\Carbon::setLocale($locale);
        } catch (\Throwable $ex) {
            // a language Carbon does not know
        }
    }

    /**
     * exment-admin-core names some folders with "-" (zh-CN, pt-BR) where Exment uses "_" (zh_CN):
     * give the texts of admin-core (admin.php) of the other spelling to the locale, when it has none.
     */
    protected static function aliasAdminLang(string $locale): void
    {
        if (strpbrk($locale, '_-') === false) {
            return;
        }
        $translator = app('translator');
        if ($translator->hasForLocale('admin.save', $locale)) {
            return;
        }
        $alias = strpos($locale, '_') !== false ? str_replace('_', '-', $locale) : str_replace('-', '_', $locale);
        $dirs = [
            function_exists('lang_path') ? lang_path() : resource_path('lang'),
            base_path('vendor/exceedone/exment-admin-core/resources/lang'),
        ];
        foreach ($dirs as $dir) {
            $file = $dir . '/' . $alias . '/admin.php';
            if (!is_file($file)) {
                continue;
            }
            $lines = include $file;
            if (!is_array($lines)) {
                return;
            }
            $flat = [];
            foreach (array_dot($lines) as $key => $value) {
                $flat['admin.' . $key] = $value;
            }
            $translator->addLines($flat, $locale);
            return;
        }
    }

    /**
     * Run a callback in the language and the time zone of a user (a mail to the user, a notification),
     * and put back the language and the time zone of the page after it.
     *
     * @param mixed $user LoginUser, a user CustomValue, the id of the user or null (null: as it is)
     * @param \Closure $callback
     * @return mixed
     */
    public static function runAs($user, \Closure $callback)
    {
        if (is_null($user)) {
            return $callback();
        }

        $locale = static::forUser($user) ?? static::match(static::siteDefault()) ?? static::siteDefault();
        $timezone = UserTimezone::forUser($user);

        $beforeLocale = \App::getLocale();
        $beforeTimezone = UserTimezone::current();
        static::apply($locale);
        UserTimezone::setCurrent($timezone);
        try {
            return $callback();
        } finally {
            static::apply($beforeLocale);
            UserTimezone::setCurrent($beforeTimezone);
        }
    }

    /**
     * A value of the settings of a user (user_settings, by the id of the user).
     *
     * @param mixed $user LoginUser, a user CustomValue, the id of the user or null
     * @param string $key
     * @return mixed
     */
    public static function userSetting($user, string $key)
    {
        if ($user instanceof LoginUser) {
            return $user->getSettingValue($key);
        }

        $userId = null;
        if (is_numeric($user)) {
            $userId = (int)$user;
        } elseif (is_object($user) && method_exists($user, 'getUserId')) {
            $userId = $user->getUserId();
        } elseif ($user instanceof \Illuminate\Database\Eloquent\Model) {
            $userId = $user->getKey();
        }
        if (is_null($userId)) {
            return null;
        }

        $settings = System::requestSession("user_setting_of.{$userId}", function () use ($userId) {
            try {
                return UserSetting::where('base_user_id', $userId)->value('settings');
            } catch (\Throwable $ex) {
                return null;
            }
        });
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }
        return array_get((array)$settings, $key);
    }

    /**
     * Forget the settings read for the users in this request (after a user changed them).
     */
    public static function clearCache(): void
    {
        System::clearRequestSession('user_setting');
        System::clearRequestSession('user_setting_of');
    }

    protected static function isLocaleName(string $name): bool
    {
        return (bool)preg_match('/^[a-zA-Z]{2,3}([_-][a-zA-Z0-9]{2,8})*$/', $name);
    }

    protected static function normalize(string $name): string
    {
        return strtolower(str_replace('_', '-', trim($name)));
    }
}
