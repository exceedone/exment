<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Illuminate\Support\Collection;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\RoleGroupType;
use Exceedone\Exment\Model\RoleGroupPermission;

class RoleGroupPermissionProvider extends ProviderBase
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
        return 'role_group_permission';
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
        $headers = [
            'role_group_id'
        ];

        // 2nd row, column view name
        $titles = [
            exmtrans('role_group.role_group_id')
        ];

        // set headers for each role_group_type
        $this->setHeadersOfType($headers, $titles);

        // add permissions
        $role_group_type = $this->getRoleGroupType();
        foreach ($role_group_type->getRoleGroupOptions() as $key => $permission) {
            $headers[] = "permissions:{$key}"; 
            $titles[] = $permission; 
        }
        $rows[] = $headers;
        $rows[] = $titles;

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

        if ($records->count() > 0) {
            $query = RoleGroupPermission::whereIn('role_group_id', $records->pluck('id'));
            $this->setRoleTypeFilter($query);
            $records = $query->orderBy('role_group_id')->orderBy('role_group_target_id')->get();
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
            $permissions = $record->permissions;

            if (is_nullorempty($permissions)) {
                continue;
            }

            // add items
            $body_items = [];
            $body_items[] = $record->role_group_id;

            // set body items for each role_group_type
            $this->setBodiesOfType($body_items, $record);

            if (!is_array($record->permissions)) {
                $permissions = [$permissions];
            }

            $role_group_type = $this->getRoleGroupType();
            foreach ($role_group_type->getRoleGroupOptions() as $key => $permission) {
                if (in_array($key, $permissions)) {
                    $body_items[] = 1; 
                } else{
                    $body_items[] = ''; 
                }
            }

            $bodies[] = $body_items;
        }

        return $bodies;
    }
 
    protected function setHeadersOfType(array &$headers, array &$titles): void
    {
    }

    protected function setBodiesOfType(array &$body_items, $record): void
    {
    }

    protected function setRoleTypeFilter(&$query)
    {
    }
    
    protected function getRoleGroupType(): RoleGroupType
    {
        return RoleGroupType::SYSTEM();
    }
}
