<?php

namespace Exceedone\Exment\Services;

/**
 * Partial CRUD Service
 */
class PartialCrudService
{
    // @phpstan-ignore-next-line
    protected static $providers = [
    ];

    /**
     * Register providers.
     *
     * @return void
     */
    // @phpstan-ignore-next-line
    public static function providers($provider, $options)
    {
        static::$providers[$provider] = $options;
    }

    // @phpstan-ignore-next-line
    public static function setAdminFormOptions($custom_table, &$form, $id = null)
    {
        static::getItem($custom_table, function ($item) use (&$form, $id) {
            $item->setAdminFormOptions($form, $id);
        });
    }

    // @phpstan-ignore-next-line
    public static function setAdminFormTools($custom_table, &$tools, $id = null)
    {
        static::getItem($custom_table, function ($item) use (&$tools, $id) {
            $item->setAdminFormTools($tools, $id);
        });
    }

    // @phpstan-ignore-next-line
    public static function setAdminShowTools($custom_table, &$tools, $id = null)
    {
        static::getItem($custom_table, function ($item) use (&$tools, $id) {
            $item->setAdminShowTools($tools, $id);
        });
    }

    // @phpstan-ignore-next-line
    public static function setGridContent($custom_table, &$form, $id = null)
    {
        static::getItem($custom_table, function ($item) use (&$form, $id) {
            $item->setGridContent($form, $id);
        });
    }

    // @phpstan-ignore-next-line
    public static function setGridRowAction($custom_table, &$actions)
    {
        static::getItem($custom_table, function ($item) use (&$actions) {
            $item->setGridRowAction($actions);
        });
    }

    // @phpstan-ignore-next-line
    public static function saving($custom_table, &$form, $id = null)
    {
        return static::getItem($custom_table, function ($item) use (&$form, $id) {
            $result = $item->saving($form, $id);

            // @phpstan-ignore-next-line
            if ($result instanceof \Symfony\Component\HttpFoundation\Response || $result instanceof \Illuminate\Http\Response) {
                return $result;
            }
        });
    }

    // @phpstan-ignore-next-line
    public static function saved($custom_table, &$form, $id = null)
    {
        return static::getItem($custom_table, function ($item) use (&$form, $id) {
            $result = $item->saved($form, $id);

            // @phpstan-ignore-next-line
            if ($result instanceof \Symfony\Component\HttpFoundation\Response || $result instanceof \Illuminate\Http\Response) {
                return $result;
            }
        });
    }

    // @phpstan-ignore-next-line
    protected static function getItem($custom_table, $callback)
    {
        foreach (static::$providers as $provider) {
            if (!in_array($custom_table->table_name, array_get($provider, 'target_tables'))) {
                continue;
            }

            $classname = array_get($provider, 'classname');
            $item = $classname::getItem($custom_table);

            $result = $callback($item);

            if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
                return $result;
            }
        }
    }
}
