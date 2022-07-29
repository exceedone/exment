<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Illuminate\Support\Collection;

/**
 * Relation Pivot table (n:n)
 */
class RelationPivotTableProvider extends ProviderBase
{
    protected $relation;

    protected $grid;

    public function __construct($args = [])
    {
        parent::__construct();

        $this->relation = array_get($args, 'relation');

        $this->grid = array_get($args, 'grid');
    }

    /**
     * get data name
     */
    public function name()
    {
        return $this->relation->getSheetName();
    }

    /**
     * get data
     */
    public function data()
    {
        // get header info
        $columnDefines = $this->getColumnDefines();
        // get header and body
        $headers = $this->getHeaders($columnDefines);

        // if only template, output only headers
        if ($this->template) {
            $bodies = [];
        } else {
            $bodies = $this->getBodies($this->getRecords(), $columnDefines);
        }
        // get output items
        $outputs = array_merge($headers, $bodies);

        return $outputs;
    }

    /**
     * get column info
     * @return mixed list. first:fixed column id, suuid, parent_id, parent_type. second: custom columns: third: created_at, updated_at, deleted_at
     */
    protected function getColumnDefines()
    {
        $columnDefines = ['parent_id','child_id'];
        if ($this->template) {
            $columnDefines[] = 'delete_flg';
        }
        return $columnDefines;
    }

    /**
     * get export headers
     * contains custom column name, column view name
     */
    protected function getHeaders($columnDefines)
    {
        // create 2 rows.
        $rows = [];

        $rows[] = $columnDefines;
        // column_view_names
        $column_view_names =  [
            $this->relation->parent_custom_table->table_view_name . '_'. exmtrans("common.id"),
            $this->relation->child_custom_table->table_view_name . '_'. exmtrans("common.id"),
        ];
        if ($this->template) {
            $column_view_names[] = trans('admin.delete');
        }
        $rows[] = $column_view_names;

        return $rows;
    }

    /**
     * get target chunk records
     */
    public function getRecords(): Collection
    {
        // get base records
        $relation_name = $this->relation->getRelationName();
        $this->grid->model()->with($relation_name);

        $records = new Collection();
        $this->grid->model()->eloquent()->chunk(100, function ($data) use (&$records, $relation_name) {
            if (is_nullorempty($records)) {
                $records = new Collection();
            }
            $datalist = $data->map(function ($d) use ($relation_name) {
                return $d->{$relation_name};
            });
            foreach ($datalist as $d) {
                foreach ($d as $record) {
                    if ($records->contains(function ($value) use ($record) {
                        return array_get($value, 'pivot.parent_id') == array_get($record, 'pivot.parent_id') &&
                               array_get($value, 'pivot.child_id') == array_get($record, 'pivot.child_id');
                    })) {
                        continue;
                    };
                    $records->push($record);
                }
            }
        }) ?? new Collection();

        $this->count = count($records);
        return $records;
    }

    /**
     * get export bodies
     */
    protected function getBodies($records, $columnDefines)
    {
        if (!isset($records)) {
            return [];
        }

        $bodies = [];

        foreach ($records as $record) {
            $body_items = [];
            // add items
            $body_items = array_merge($body_items, $this->getBodyItems($record, $columnDefines, "pivot."));
            $bodies[] = $body_items;
        }

        return $bodies;
    }

    /**
     * get export body items
     */
    protected function getBodyItems($record, $columns, $array_header_key = null)
    {
        $body_items = [];
        foreach ($columns as $column) {
            // get key.
            $key = (isset($array_header_key) ? $array_header_key : "").$column;
            $value = array_get($record, $key);
            if (is_array($value)) {
                $value = implode(",", $value);
            }
            $body_items[] = $value;
        }
        return $body_items;
    }
}
