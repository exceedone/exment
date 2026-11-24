<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\MenuType;

/**
 * The title of a menu Exment made, in the language of the user (ISS02858).
 *
 * admin_menu.title holds a text: the one of the language the site was installed in (or an update ran
 * in). A user who chose another language (7.2.4) kept reading the left menu in that first language.
 *
 * For the menus Exment itself made - the pages of the system (menu_type "system"), the groups
 * (menu_type "parent_node") and the tables the install makes (menu_type "table": base_info, user,
 * organization, information, mail_template, system_logs) - the title is shown in the language of the
 * user, as long as it is still a text Exment wrote: one of the titles the templates of the install
 * (system_template, templates/system_logs) or the language files (menu.system_definitions) have for
 * that menu, in any language. It is then answered from the same source in the language of the user, so a user in the
 * language the title was written in reads exactly the stored text.
 *
 * A title somebody changed is no such text and is shown as it is. The menus of the other tables, of
 * the plugins and the custom ones are never touched.
 */
class MenuTitle
{
    /** the key of the titles in the cache of Laravel (with the times of the source files) */
    protected const CACHE_KEY = 'exment_menu_titles:';

    /** the titles of the install template, then the ones of the language files */
    protected const SOURCES = ['template', 'lang'];

    /** the templates the install (and the update) of Exment imports the menus of */
    protected const TEMPLATES = ['system_template', 'templates/system_logs'];

    /**
     * The known titles, for this request.
     *
     * @var array<string, array<string, array<string, string>>>|null "type|name" => source => locale => title
     */
    protected static $known = null;

    /**
     * The rows of the menu with the titles in the language of the user.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param string|null $locale null: the language of the page
     * @return array<int, array<string, mixed>>
     */
    public static function localizeRows(array $rows, ?string $locale = null): array
    {
        foreach ($rows as $index => $row) {
            if (!static::isTarget($row)) {
                continue;
            }
            $rows[$index]['title'] = static::localize($row, $locale);
        }
        return $rows;
    }

    /**
     * The title of a row of the menu in a language. The stored title when the menu is not one of
     * Exment, when the title was changed, or when that language has no title for it.
     *
     * @param array<string, mixed> $row menu_type, menu_name, title
     * @param string|null $locale null: the language of the page
     * @return mixed the stored title as it is when nothing translates
     */
    public static function localize(array $row, ?string $locale = null)
    {
        $stored = array_get($row, 'title');
        if (!is_string($stored) || !static::isTarget($row)) {
            return $stored;
        }
        $titles = array_get(static::known(), static::key($row));
        if (!is_array($titles)) {
            return $stored;
        }
        $locale = $locale ?? strval(app()->getLocale());
        $text = trim($stored);
        foreach (static::SOURCES as $source) {
            $byLocale = array_get($titles, $source, []);
            if (!is_array($byLocale) || !in_array($text, $byLocale, true)) {
                continue;
            }
            $title = array_get($byLocale, $locale);
            // a language the source does not have: the stored text
            return is_string($title) && $title !== '' ? $title : $stored;
        }
        return $stored;
    }

    /**
     * Whether a row may be a menu Exment made: a page of the system, a group or a table (only the
     * tables of the templates of the install are known, see build()).
     *
     * @param array<string, mixed> $row
     */
    public static function isTarget(array $row): bool
    {
        $type = strval(array_get($row, 'menu_type'));
        return in_array($type, [MenuType::SYSTEM, MenuType::PARENT_NODE, MenuType::TABLE], true)
            && !is_nullorempty(array_get($row, 'menu_name'));
    }

    /**
     * The languages Exment has (the folders of resources/lang).
     *
     * @return array<int, string>
     */
    public static function locales(): array
    {
        $locales = [];
        foreach (glob(static::packagePath('resources/lang') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $locales[] = basename($dir);
        }
        sort($locales);
        return $locales;
    }

    /**
     * Forget the known titles (the tests; a change of the source files changes the key by itself).
     */
    public static function reset(): void
    {
        static::$known = null;
    }

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    protected static function known(): array
    {
        if (isset(static::$known)) {
            return static::$known;
        }
        $key = static::CACHE_KEY . static::sourceStamp();
        try {
            $known = \Cache::remember($key, 86400, function () {
                return static::build();
            });
        } catch (\Throwable $ex) {
            // no cache that works: read the sources
            $known = null;
        }
        static::$known = is_array($known) ? $known : static::build();
        return static::$known;
    }

    /**
     * Read the titles of the install template and of the language files, in every language.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    protected static function build(): array
    {
        $known = [];

        // the templates of the install: config.json is English, lang/<locale>/lang.json the other languages
        foreach (static::templateFiles() as $locale => $files) {
            foreach ($files as $file) {
                $json = is_file($file) ? json_decode(strval(file_get_contents($file)), true) : null;
                foreach ((array)array_get((array)$json, 'admin_menu', []) as $menu) {
                    if (!is_array($menu) || !static::isTarget($menu) || !is_string(array_get($menu, 'title')) || trim($menu['title']) === '') {
                        continue;
                    }
                    $known[static::key($menu)]['template'][$locale] = trim($menu['title']);
                }
            }
        }

        // the language files: the pages of the system and the groups an update adds (Menu::systemTitle)
        foreach (static::locales() as $locale) {
            $definitions = trans('exment::exment.menu.system_definitions', [], $locale);
            if (!is_array($definitions)) {
                continue;
            }
            foreach ($definitions as $name => $title) {
                if (!is_string($title) || trim($title) === '') {
                    continue;
                }
                foreach ([MenuType::SYSTEM, MenuType::PARENT_NODE] as $type) {
                    $known[$type . '|' . $name]['lang'][$locale] = trim($title);
                }
            }
        }
        return $known;
    }

    /**
     * The files of the templates of the install, by language.
     *
     * @return array<string, array<int, string>>
     */
    protected static function templateFiles(): array
    {
        $files = [];
        foreach (static::TEMPLATES as $template) {
            $base = static::packagePath($template);
            $files['en'][] = $base . '/config.json';
            foreach (glob($base . '/lang/*/lang.json') ?: [] as $file) {
                $files[basename(dirname($file))][] = $file;
            }
        }
        return $files;
    }

    /**
     * @param array<string, mixed> $row
     */
    protected static function key(array $row): string
    {
        return strval(array_get($row, 'menu_type')) . '|' . strval(array_get($row, 'menu_name'));
    }

    /**
     * The times of the source files: an update of Exment changes them, and the key with them.
     */
    protected static function sourceStamp(): string
    {
        $stamp = [];
        $files = array_merge(
            array_merge(...array_values(static::templateFiles())),
            glob(static::packagePath('resources/lang') . '/*/exment.php') ?: []
        );
        foreach ($files as $file) {
            $stamp[] = $file . ':' . (is_file($file) ? filemtime($file) . ':' . filesize($file) : '0');
        }
        return md5(implode('|', $stamp));
    }

    protected static function packagePath(string $path): string
    {
        return dirname(__DIR__, 2) . '/' . $path;
    }
}
