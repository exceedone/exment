<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Illuminate\Support\Collection;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Illuminate\Pagination\LengthAwarePaginator;

class RoleGroupUserOrganizationProvider extends ProviderBase
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
        return 'role_group_user_organization';
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
            'role_group_id',
            'role_group_user_org_type',
            'role_group_target_id',
            'delete_flg'
        ];

        // 2nd row, column view name
        $rows[] = [
            exmtrans('role_group.role_group_id'),
            exmtrans('role_group.role_group_user_org_type'),
            exmtrans('role_group.role_group_user_org_target_id'),
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
        $func = function ($data) use (&$records) {
            if (is_nullorempty($records)) {
                $records = new Collection();
            }
            $records = $records->merge($data);
        };
        if ($this->grid->model()->eloquent() instanceof LengthAwarePaginator) {
            $this->grid->model()->chunk($func, 100) ?? new Collection();
        } else {
            $this->grid->model()->eloquent()->chunk(100, $func) ?? new Collection();
        }

        if ($records->count() > 0) {
            $records = RoleGroupUserOrganization::whereIn('role_group_id', $records->pluck('id'))
                ->get();
        }

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
            $body_items[] = $record->role_group_id;
            $body_items[] = $record->role_group_user_org_type;
            $body_items[] = $record->role_group_target_id;

            $bodies[] = $body_items;
        }

        return $bodies;
    }
}
