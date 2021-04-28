<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Illuminate\Database\Eloquent\Collection;

class OperationLogProvider extends ProviderBase
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
        return 'operation_log';
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
            'user_id',
            'path',
            'method',
            'ip',
            'input',
        ];

        // 2nd row, column view name
        $rows[] = [
            exmtrans('common.id'),
            exmtrans('operation_log.user_id'),
            exmtrans('operation_log.path'),
            exmtrans('operation_log.method'),
            exmtrans('operation_log.ip'),
            exmtrans('operation_log.input'),
        ];

        return $rows;
    }

    /**
     * get target chunk records
     */
    public function getRecords() : Collection
    {
        $records = new Collection;
        $this->grid->model()->with(['user', 'user.base_user'])->chunk(function ($data) use (&$records) {
            if (is_nullorempty($records)) {
                $records = new Collection;
            }
            $records = $records->merge($data);
        }) ?? new Collection;

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
            $body_items[] = $record->user_name;
            $body_items[] = $record->path;
            $body_items[] = $record->method;
            $body_items[] = $record->ip;
            $body_items[] = $record->input;

            $bodies[] = $body_items;
        }

        return $bodies;
    }
}
