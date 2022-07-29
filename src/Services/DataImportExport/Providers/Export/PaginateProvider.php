<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Illuminate\Support\Collection;

class PaginateProvider extends ProviderBase
{
    /**
     * Whether is get all data
     *
     * @var boolean
     */
    protected $isAll = false;

    /**
     * Widget grid
     *
     */
    protected $grid;

    /**
     * file headers array.
     *
     * @var array
     */
    protected $headers = [];

    /**
     * file name.
     *
     * @var string
     */
    protected $filename;

    public function __construct($args = [])
    {
        parent::__construct();
        $this->isAll = array_get($args, 'isAll');
        $this->grid = array_get($args, 'grid');
        $this->headers = array_get($args, 'headers');
        $this->filename = array_get($args, 'filename');
    }

    /**
     * get data name
     */
    public function name()
    {
        return $this->filename;
    }

    /**
     * get data
     */
    public function data()
    {
        // get header and body
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
        // 1st row, column name
        $rows[] = collect($this->headers)->map(function ($header) {
            return array_get($header, 'key');
        });

        // 2st row, column view name
        $rows[] = collect($this->headers)->map(function ($header) {
            return array_get($header, 'label');
        });

        return $rows;
    }

    /**
     * get target chunk records
     */
    public function getRecords(): Collection
    {
        if ($this->isAll) {
            $records = new Collection();
            $callback = $this->grid->chunk(function ($data) use (&$records) {
                if (is_nullorempty($records)) {
                    $records = new Collection();
                }
                $records = $records->merge(collect($data));
            });
        } else {
            $records = $this->grid->getCurrentPage();
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

            foreach ($this->headers as $header) {
                $body_items[] = array_get((array)$record, array_get($header, 'key'));
            }
            $bodies[] = $body_items;
        }

        return $bodies;
    }
}
