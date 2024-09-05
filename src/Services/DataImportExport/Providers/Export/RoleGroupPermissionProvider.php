<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Illuminate\Support\Collection;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\RoleGroupType;
use Exceedone\Exment\Model\RoleGroupPermission;
use Illuminate\Pagination\LengthAwarePaginator;

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
            'id',
            'role_group_id',
            'role_group_permission_type',
            'role_group_target_id',
        ];

        // 2nd row, column view name
        $titles = [
            exmtrans('common.id'),
            exmtrans('role_group.role_group_id'),
            exmtrans('role_group.role_group_permission_type'),
            exmtrans('role_group.role_group_target_id'),
        ];

        // add table permissions
        foreach ($this->getRoleGroupType() as $role_group_type) {
            foreach ($role_group_type->getRoleGroupOptions() as $key => $permission) {
                $headers[] = "permissions.{$role_group_type}.{$key}"; 
                $titles[] = exmtrans("role_group.role_type_options.$role_group_type") . '.' . $permission; 
            }
        }

        // append 1st row, system column name
        $headers = array_merge($headers, [
            'created_at',
            'updated_at',
        ]);
        // append 2nd row, system column view name
        $titles = array_merge($titles, [
            exmtrans('common.created_at'),
            exmtrans('common.updated_at'),
        ]);
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
            $records = RoleGroupPermission::whereIn('role_group_id', $records->pluck('id'))
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
            $body_items[] = $record->id;
            $body_items[] = $record->role_group_id;
            $body_items[] = $record->role_group_permission_type;
            $body_items[] = $record->role_group_target_id;
            $permissions = $record->permissions;
            if (!is_array($record->permissions)) {
                $permissions = [$permissions];
            }

            $role_type = $record->role_group_permission_type;
            foreach ($this->getRoleGroupType() as $role_group_type) {
                foreach ($role_group_type->getRoleGroupOptions() as $key => $permission) {
                    if (in_array($role_group_type, $this->getTargetRoleGroupType($role_type)) && in_array($key, $permissions)) {
                        $body_items[] = 1; 
                    } else{
                        $body_items[] = ''; 
                    }
                }
            }

            $body_items[] = $record->created_at;
            $body_items[] = $record->updated_at;

            $bodies[] = $body_items;
        }

        return $bodies;
    }
    
    protected function getTargetRoleGroupType($role_type)
    {
        switch ($role_type) {
            case RoleType::SYSTEM:
                return [RoleGroupType::SYSTEM, RoleGroupType::ROLE_GROUP];
            case RoleType::TABLE:
                return [RoleGroupType::MASTER, RoleGroupType::TABLE];
            case RoleType::PLUGIN:
                return [RoleGroupType::PLUGIN];
        }
        return [];
    }
    
    protected function getRoleGroupType()
    {
        return [
            RoleGroupType::SYSTEM(),
            RoleGroupType::ROLE_GROUP(),
            RoleGroupType::PLUGIN(),
            RoleGroupType::MASTER(),
            RoleGroupType::TABLE(),
        ];
    }
}
