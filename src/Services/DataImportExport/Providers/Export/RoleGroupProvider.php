<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Illuminate\Support\Collection;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;

class RoleGroupProvider extends ProviderBase
{
    protected $grid;

    public function __construct($args = [])
    {
        parent::__construct();
        $this->grid = array_get($args, 'grid');
    }

    /**
     * get data name
     */
    public function name()
    {
        return 'role_group';
    }

    /**
     * get data
     */
    public function data()
    {
        $headers = $this->getHeaders();

        // if only template, output only headers
        if ($this->template) {
            $bodies = [];
        } else {
            $bodies = $this->getBodies($this->getRecords());
        }
        // get output items
        $outputs = array_merge($headers, $bodies);

        return $outputs;
    }

    /**
     * get export headers
     */
    protected function getHeaders()
    {
        // create 2 rows.
        $rows = [];

        // 1st row, column name
        $rows[] = [
            'id',
            'role_group_name',
            'role_group_view_name',
            'role_group_order',
            'description',
            'delete_flg'
        ];

        // 2nd row, column view name
        $rows[] = [
            exmtrans('common.id'),
            exmtrans('role_group.role_group_name'),
            exmtrans('role_group.role_group_view_name'),
            exmtrans('role_group.role_group_order'),
            exmtrans('custom_table.field_description'),
            exmtrans('common.deleted')
        ];

        return $rows;
    }

    /**
     * get target chunk records
     */
    public function getRecords(): Collection
    {
        $records = new Collection();
        $this->grid->model()->chunk(function ($data) use (&$records) {
            if (is_nullorempty($records)) {
                $records = new Collection();
            }
            $records = $records->merge($data);
        }) ?? new Collection();

        $this->count = count($records);
        return $records;
    }

    /**
     * get export bodies
     */
    protected function getBodies($records)
    {
        if (!isset($records)) {
            return [];
        }

        $bodies = [];

        foreach ($records as $record) {
            $body_items = [];
            // add items
            $body_items[] = $record->id;
            $body_items[] = $record->role_group_name;
            $body_items[] = $record->role_group_view_name;
            $body_items[] = $record->role_group_order;
            $body_items[] = $record->description;

            $bodies[] = $body_items;
        }

        return $bodies;
    }
}
