<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Illuminate\Support\Collection;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;

class LoginUserProvider extends ProviderBase
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
        return 'login_user';
    }

    /**
     * get data
     */
    public function data()
    {
        $headers = $this->getHeaders();

        $bodies = $this->getBodies($this->getRecords());
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
            'user_code',
            'user_name',
            'email',
            'use_loginuser',
            'create_password_auto',
            'password',
            'send_password',
        ];

        // 2nd row, column view name
        $table_user = CustomTable::getEloquent(SystemTableName::USER);
        $rows[] = [
            exmtrans('common.id'),
            CustomColumn::getEloquent('user_code', $table_user)->column_view_name ?? 'user_code',
            CustomColumn::getEloquent('user_name', $table_user)->column_view_name ?? 'user_name',
            CustomColumn::getEloquent('email', $table_user)->column_view_name ?? 'email',
            exmtrans('user.use_loginuser'),
            exmtrans('user.create_password_auto'),
            exmtrans('user.password'),
            exmtrans('user.send_password'),
        ];

        if (!System::first_change_password()) {
            $rows[0][] = 'password_reset_flg';
            $rows[1][] = exmtrans('user.password_reset_flg');
        }

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
            $body_items[] = $record->getValue('user_code');
            $body_items[] = $record->getValue('user_name');
            $body_items[] = $record->getValue('email');
            $body_items[] = isset($record->login_user) ? '1' : null; // use_loginuser
            $body_items[] = null; // for password

            $bodies[] = $body_items;
        }

        return $bodies;
    }
}
