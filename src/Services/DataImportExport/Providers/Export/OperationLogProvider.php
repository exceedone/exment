<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Illuminate\Support\Collection;

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
            'user_name',
            'path',
            'method',
            'ip',
            'input',
            'created_at',
        ];

        // 2nd row, column view name
        $rows[] = [
            exmtrans('operation_log.user_name'),
            exmtrans('operation_log.method'),
            exmtrans('operation_log.path'),
            exmtrans('operation_log.ip'),
            exmtrans('operation_log.input'),
            trans('admin.created_at'),
        ];

        return $rows;
    }

    /**
     * get target chunk records
     */
    public function getRecords(): Collection
    {
        $records = new Collection();
        $this->grid->applyQuickSearch();
        $this->grid->getFilter()->with(['user', 'user.base_user'])->chunk(function ($data) use (&$records) {
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
            $body_items[] = $record->user_name;
            $body_items[] = $record->method;
            $body_items[] = $record->path;
            $body_items[] = $record->ip;
            $body_items[] = $record->input;
            $body_items[] = $record->created_at;

            $bodies[] = $body_items;
        }

        return $bodies;
    }
}
