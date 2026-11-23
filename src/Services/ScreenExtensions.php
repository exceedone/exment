<?php

namespace Exceedone\Exment\Services;

/**
 * Places in the screens of Exment where an application built on it (registered from its
 * service provider) adds fields, columns, buttons or behaviour, without changing the screens:
 *
 * - custom_column.grid      fn($grid, ?CustomTable $table)                  the list of the columns
 * - custom_column.options   fn($form, ?CustomTable $table, $id)             the options of a column (first)
 * - custom_column.form      fn($form, ?CustomTable $table, $id)             the form of a column (saving, scripts)
 * - custom_table.options    fn($form, $id)                                  the options of a table (after the icon)
 * - custom_table.form       fn($form, $id)                                  the form of a table (saving, scripts)
 * - system.advanced         fn($form)                                       the advanced system settings (last)
 * - data_form.form          fn($form, CustomTable $table, $id)              the form of a row
 * - data_form.tools         fn($tools, CustomTable $table, $id)             the buttons of the form of a row
 * - data_form.column        fn(CustomFormColumn $column, CustomTable $table): bool   false hides a field
 * - data_grid.tools         fn($tools, CustomTable $table, $view)           the buttons of the list of rows
 * - data_show.attachment    fn(CustomTable $table): array                   ['uploader' => bool, 'title' => string]
 * - import.items            fn(array $items, CustomTable $table): array     the import items of the import menu
 * - notify.poll             fn(array $page): array                          the answer of the navbar poll
 * - plugin.installing       fn(Plugin $plugin, array $json)                 a plugin before it is saved
 * - install.seeded          fn()                                            after the data of the install
 */
class ScreenExtensions
{
    /** @var array<string, array<int, callable>> */
    protected static $callbacks = [];

    public static function add(string $place, callable $callback): void
    {
        static::$callbacks[$place][] = $callback;
    }

    public static function has(string $place): bool
    {
        return !empty(static::$callbacks[$place]);
    }

    /**
     * Call the callbacks of the place.
     *
     * @param mixed ...$args
     * @return array<int, mixed> their answers
     */
    public static function call(string $place, ...$args): array
    {
        $results = [];
        foreach (static::$callbacks[$place] ?? [] as $callback) {
            $results[] = call_user_func_array($callback, $args);
        }
        return $results;
    }

    /**
     * Whether no callback of the place answers false.
     *
     * @param mixed ...$args
     */
    public static function allows(string $place, ...$args): bool
    {
        foreach (static::call($place, ...$args) as $result) {
            if ($result === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Pass the value through the callbacks of the place: fn($value, ...$args) returns it changed.
     *
     * @param mixed $value
     * @param mixed ...$args
     * @return mixed
     */
    public static function filter(string $place, $value, ...$args)
    {
        foreach (static::$callbacks[$place] ?? [] as $callback) {
            $value = call_user_func($callback, $value, ...$args);
        }
        return $value;
    }

    /**
     * Forget the callbacks (tests).
     */
    public static function reset(): void
    {
        static::$callbacks = [];
    }
}
