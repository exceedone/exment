<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\AuthorityValue;
use Encore\Admin\Facades\Admin;
use Carbon\Carbon;

trait CustomTableTrait
{
    /**
     * Find record using table name
     * @param mixed $model_name
     * @return mixed
     */
    public static function findByName($model_name, $with_custom_columns = false)
    {
        $query = static::where('table_name', $model_name);
        if ($with_custom_columns) {
            $query = $query->with('custom_columns');
        }
        return $query->first();
    }

    /**
     * Find record using database table name
     * @param mixed $table_name
     * @return mixed
     */
    public static function findByDBTableName($db_table_name, $with_custom_columns = false)
    {
        $query = static::where('suuid', preg_replace('/^exm__/', '', $db_table_name));
        if ($with_custom_columns) {
            $query = $query->with('custom_columns');
        }
        return $query->first();
    }

    /**
     * get custom table eloquent.
     * @param mixed $obj id, table_name, CustomTable object, CustomValue object.
     */
    public static function getEloquent($obj)
    {
        if ($obj instanceof \stdClass) {
            $obj = (array)$obj;
        }
        // get id or array value
        if (is_array($obj)) {
            // get id or table_name
            if (array_key_value_exists('id', $obj)) {
                $obj = array_get($obj, 'id');
            } elseif (array_key_value_exists('table_name', $obj)) {
                $obj = array_get($obj, 'table_name');
            } else {
                return null;
            }
        }

        // get eloquent model
        if (is_numeric($obj)) {
            $obj = static::find($obj);
        } elseif (is_string($obj)) {
            $obj = static::findByName($obj);
        } elseif (is_array($obj)) {
            $obj = static::findByName(array_get($obj, 'table_name'));
        } elseif ($obj instanceof CustomTable) {
            // nothing
        } elseif ($obj instanceof CustomValue) {
            $obj = $obj->getCustomTable();
        }
        return $obj;
    }

    /**
     * get table list.
     * But filter these:
     *     Get only has authority
     *     showlist_flg is true
     */
    public static function filterList($model = null, $options = [])
    {
        $options = array_merge(
            [
                'getModel' => true
            ],
            $options
        );
        if (!isset($model)) {
            $model = new self;
        }
        $model = $model->where('showlist_flg', true);

        // if not exists, filter model using permission
        if (!Admin::user()->hasPermission(AuthorityValue::CUSTOM_TABLE)) {
            // get tables has custom_table permission.
            $permission_tables = Admin::user()->allHasPermissionTables(AuthorityValue::CUSTOM_TABLE);
            $permission_table_ids = $permission_tables->map(function ($permission_table) {
                return array_get($permission_table, 'id');
            });
            // filter id;
            $model = $model->whereIn('id', $permission_table_ids);
        }

        if ($options['getModel']) {
            return $model->get();
        }
        return $model;
    }
}
