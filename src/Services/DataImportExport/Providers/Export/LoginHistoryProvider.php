<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Illuminate\Support\Collection;

class LoginHistoryProvider extends ProviderBase
{
    // @phpstan-ignore-next-line
    protected $grid;

    // @phpstan-ignore-next-line
    public function __construct($args = [])
    {
        parent::__construct();
        $this->grid = array_get($args, 'grid');
    }

    /**
     * get data name
     */
    // @phpstan-ignore-next-line
    public function name()
    {
        return 'login_history';
    }

    /**
     * get data
     */
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
    protected function getHeaders()
    {
        // create 2 rows.
        $rows = [];

        // 1st row, column name
        $rows[] = [
            'created_at',
            'user_code',
            'user_name',
            'ip_address',
            'country_code',
            'country',
            'region',
            'city',
            'login_type',
            'login_provider',
            'via_remember',
            'is_new_ip',
            'auth_2factor_verified',
            'user_agent',
        ];

        // 2nd row, column view name
        $rows[] = [
            exmtrans('login_history.login_at'),
            exmtrans('login_history.user_code'),
            exmtrans('login_history.user_name'),
            exmtrans('login_history.ip_address'),
            exmtrans('login_history.country_code'),
            exmtrans('login_history.country'),
            exmtrans('login_history.region'),
            exmtrans('login_history.city'),
            exmtrans('login_history.login_type'),
            exmtrans('login_history.login_provider'),
            exmtrans('login_history.via_remember'),
            exmtrans('login_history.is_new_ip'),
            exmtrans('login_history.auth_2factor_verified'),
            exmtrans('login_history.user_agent'),
        ];

        return $rows;
    }

    /**
     * get target chunk records
     */
    // @phpstan-ignore-next-line
    public function getRecords(): Collection
    {
        $records = new Collection();
        $this->grid->applyQuickSearch();
        $this->grid->getFilter()->chunk(function ($data) use (&$records) {
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
    // @phpstan-ignore-next-line
    protected function getBodies($records)
    {
        if (!isset($records)) {
            return [];
        }

        $bodies = [];

        foreach ($records as $record) {
            $body_items = [];
            // add items
            $body_items[] = $record->created_at;
            $body_items[] = $this->escapeFormula($record->user_code);
            $body_items[] = $this->escapeFormula($record->user_name);
            $body_items[] = $record->ip_address;
            $body_items[] = $record->country_code;
            $body_items[] = $record->country;
            $body_items[] = $record->region;
            $body_items[] = $record->city;
            $body_items[] = $record->login_type;
            $body_items[] = $this->escapeFormula($record->login_provider);
            $body_items[] = boolval($record->via_remember) ? 1 : 0;
            $body_items[] = boolval($record->is_new_ip) ? 1 : 0;
            $body_items[] = is_null($record->auth_2factor_verified) ? null : (boolval($record->auth_2factor_verified) ? 1 : 0);
            $body_items[] = $this->escapeFormula($record->user_agent);

            $bodies[] = $body_items;
        }

        return $bodies;
    }

    /**
     * The value is sent from the browser (Ex. user agent), so prevent it from being executed
     * as a formula when the exported file is opened by spreadsheet software.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function escapeFormula($value)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"])) {
            return "'" . $value;
        }

        return $value;
    }
}
