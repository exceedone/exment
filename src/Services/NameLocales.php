<?php

namespace Exceedone\Exment\Services;

/**
 * The names of the things the site made, in the language of the user (ISS02859, 7.3).
 *
 * A table, a column, a view or a menu has its name (table_view_name, column_view_name,
 * view_view_name, title): the name it was made with. It may also have a name for each other
 * language, kept in its options as "name_locale_<locale>" (name_locale_en, name_locale_de...).
 * The pages show the name of the user's language, or the name when there is none.
 *
 * The name itself is what is stored, searched, checked, sent by the API and written to
 * templates and exports. So where a name is edited, copied, exported or read by a program, the
 * names of the languages are OFF and every model answers its name as stored:
 * - the settings pages of tables, columns, views, menus, templates, Studio and copies
 *   (OFF_PATHS: saving such a form must never write a translation into the name),
 * - the API and MCP (api/*: they send the names of the languages beside it, as name_locales;
 *   webapi/*, the ajax of the pages, shows the names of the language),
 * - the export and the import of data, and the code that asks for it (raw()).
 */
class NameLocales
{
    /** The key in the options of a name in one language. */
    public const PREFIX = 'name_locale_';

    /** The admin pages (after the admin prefix) where the names are as stored. */
    public const OFF_PATHS = [
        'table', 'table/*', 'column/*', 'view/*', 'copy/*', 'auth/menu', 'auth/menu/*', 'auth/menu_arrange*',
        'template', 'template/*', 'studio', 'studio/*', 'api/*', 'data/*/import*', 'data/*/export*',
    ];

    /** @var int how many raw() are running, plus one for off() */
    protected static $off = 0;

    /** @var array{0: int, 1: bool}|null the request it was worked out for, and whether it is on an OFF page */
    protected static $offPath = null;

    /** Whether the names of the languages are used now. */
    public static function active(): bool
    {
        if (static::$off > 0) {
            return false;
        }
        $id = app()->bound('request') ? spl_object_id(request()) : 0;
        if (static::$offPath === null || static::$offPath[0] !== $id) {
            static::$offPath = [$id, static::isOffRequest()];
        }
        return !static::$offPath[1];
    }

    /** The names as stored for the rest of this request. */
    public static function off(): void
    {
        static::$off++;
    }

    /**
     * Run with the names as stored.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function raw(callable $callback)
    {
        static::$off++;
        try {
            return $callback();
        } finally {
            static::$off--;
        }
    }

    /** Forget what was worked out (the tests; a request that changes the page). */
    public static function reset(): void
    {
        static::$off = 0;
        static::$offPath = null;
    }

    public static function key(string $locale): string
    {
        return static::PREFIX . $locale;
    }

    /**
     * The name in the language of the page (or $locale): the one of the options, or the stored one.
     *
     * @param array<string, mixed>|null $options
     * @param mixed $stored
     * @return mixed
     */
    public static function pick(?array $options, $stored, ?string $locale = null)
    {
        if (!static::active() || empty($options)) {
            return $stored;
        }
        $locale = $locale ?? strval(app()->getLocale());
        $name = $options[static::key($locale)] ?? null;
        return is_string($name) && trim($name) !== '' ? $name : $stored;
    }

    /**
     * The names of the languages an object has: locale => name (for the API).
     *
     * @param array<string, mixed>|null $options
     * @return array<string, string>
     */
    public static function all(?array $options): array
    {
        $out = [];
        foreach ((array) $options as $key => $value) {
            if (is_string($key) && strpos($key, static::PREFIX) === 0 && is_string($value) && trim($value) !== '') {
                $out[substr($key, strlen(static::PREFIX))] = $value;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * The languages a name may be given in: the ones the users may choose, but the language of
     * the site (its names are the names themselves).
     *
     * @return array<int, string>
     */
    public static function locales(): array
    {
        $site = UserLocale::siteDefault();
        return array_values(array_filter(UserLocale::allowed(), function ($locale) use ($site) {
            return $locale !== $site;
        }));
    }

    /**
     * The fields "the names of the other languages" in the options of a settings form.
     *
     * @param mixed $form the embedded form of the options
     */
    public static function addFields($form, string $label): void
    {
        $locales = static::locales();
        if (empty($locales)) {
            return;
        }
        $form->exmheader(exmtrans('name_locales.header'))->hr();
        $form->description(exmtrans('name_locales.help', $label));
        foreach ($locales as $locale) {
            $form->text(static::key($locale), exmtrans('name_locales.name_in', static::localeName($locale)))
                ->rules('nullable|max:256');
        }
    }

    /**
     * The same as a field "name_locales" of its own (views and menus: they have no options in
     * the form), written through NameLocalesTrait.
     *
     * @param mixed $form
     */
    public static function addEmbeds($form, string $label): void
    {
        $locales = static::locales();
        if (empty($locales)) {
            return;
        }
        $form->embeds('name_locales', exmtrans('name_locales.header'), function ($form) use ($locales, $label) {
            $form->description(exmtrans('name_locales.help', $label));
            foreach ($locales as $locale) {
                $form->text($locale, exmtrans('name_locales.name_in', static::localeName($locale)))
                    ->rules('nullable|max:256');
            }
        });
    }

    public static function localeName(string $locale): string
    {
        return UserLocale::label($locale);
    }

    protected static function isOffRequest(): bool
    {
        if (!app()->bound('request')) {
            return false;
        }
        $request = request();
        $prefix = trim(strval(config('admin.route.prefix')), '/');
        foreach (static::OFF_PATHS as $path) {
            if ($request->is(ltrim($prefix . '/' . $path, '/'))) {
                return true;
            }
        }
        // a grid exported as a file
        return $request->query->has('_export_');
    }
}
