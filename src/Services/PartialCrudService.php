<?php
namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\InitializeStatus;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomTable;

/**
 * Partial CRUD Service
 */
class PartialCrudService
{
    protected static $providers = [
    ];

    /**
     * Register providers.
     *
     * @return void
     */
    public static function providers($provider, $options)
    {
        static::$providers[$provider] = $options;
    }

    public static function setAdminFormOptions($custom_table, &$form, $id = null){
        static::getItem($custom_table, function($item) use(&$form, $id){
            $item->setAdminFormOptions($form, $id);
        });
    }

    public static function saving($custom_table, &$form, $id = null){
        static::getItem($custom_table, function($item) use(&$form, $id){
            $item->saving($form, $id);
        });
    }

    public static function saved($custom_table, &$form, $id = null){
        static::getItem($custom_table, function($item) use(&$form, $id){
            $item->saved($form, $id);
        });
    }

    protected static function getItem($custom_table, $callback){
        foreach(static::$providers as $provider){
            if(!in_array($custom_table->table_name, array_get($provider, 'target_tables'))){
                continue;
            }

            $classname = array_get($provider, 'classname');
            $item = $classname::getItem($custom_table);

            $callback($item);
        }
    }
}
