<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Import;

use Exceedone\Exment\Services\DataImportExport\Providers\Import;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\RelationType;

class CustomTableAction implements ActionInterface
{
    /**
     * target custom table
     */
    protected $custom_table;

    /**
     * custom_table's relations
     */
    protected $relations;

    /**
     * import data filter
     */
    protected $filter;

    protected $primary_key;

    public function __construct($args = [])
    {
        $this->custom_table = array_get($args, 'custom_table');
        $this->filter = array_get($args, 'filter');

        // get relations
        $this->relations = CustomRelation::getRelationsByParent($this->custom_table);

        $this->primary_key = array_get($args, 'primary_key', 'id');
    }

    /**
     * Import and validate the divided data
     *
     * @param array $datalist
     * @param array $options
     * @return array
     */
    public function importChunk($datalist, $options = [])
    {
        $messages = [];
        $data_import_cnt = 0;

        foreach ($datalist as $table_name => &$data) {
            if ($table_name == Define::SETTING_SHEET_NAME) {
                continue;
            }

            // get setting info
            if (array_has($datalist, Define::SETTING_SHEET_NAME)) {
                $settings = $this->getImportTableSetting($datalist[Define::SETTING_SHEET_NAME], $table_name);
                $options['setting'] = $settings;
            }

            $provider = $this->getProvider($table_name);
            if (!isset($provider)) {
                continue;
            }

            $import_loop_count = 0;
            $take = $options['take'] ?? 100;
            $data_import_cnt = 0;
            while (true) {
                $options['row_start'] = ($import_loop_count * $take) + 1;
                $options['row_end'] = (($import_loop_count + 1) * $take);

                // execute command
                if (isset($options['command'])) {
                    $options['command']->line(exmtrans(
                        'command.import.file_row_info',
                        $options['file_name'] ?? null,
                        $table_name,
                        /** @phpstan-ignore-next-line Offset 'row_start' on non-empty-array on left side of ?? always exists and is not nullable. */
                        $options['row_start'] ?? null,
                        /** @phpstan-ignore-next-line Offset 'row_start' on non-empty-array on left side of ?? always exists and is not nullable. */
                        $options['row_end'] ?? null
                    ));
                }

                // get target data and model list
                $dataObject = $provider->getDataObject($data, $options);
                // check has data
                if (empty($dataObject)) {
                    break;
                }

                // validate data
                list($data_import, $error_data) = $provider->validateImportData($dataObject);

                // if has error data, return error data
                if (is_array($error_data) && count($error_data) > 0) {
                    $error_msg = [];
                    if ($data_import_cnt > 0) {
                        $messages[] = $table_name.':'.$data_import_cnt;
                    }
                    if (count($messages) > 0) {
                        $error_msg[] = exmtrans('command.import.error_info_ex', implode(',', $messages));
                    }
                    $error_msg[] = exmtrans('command.import.error_info');
                    $error_msg[] = implode("\r\n", $error_data);

                    // execute command
                    if (isset($options['command'])) {
                        $options['command']->error(exmtrans(
                            'command.import.file_row_error',
                            $options['file_name'] ?? null,
                            $table_name,
                            $options['start'] ?? null,
                            $options['end'] ?? null,
                            implode("\r\n", $error_msg)
                        ));
                    }

                    return [
                        'result' => false,
                    ];
                }

                foreach ($data_import as $index => &$row) {
                    // call dataProcessing if method exists
                    if (method_exists($provider, 'dataProcessing')) {
                        $row['data'] = $provider->dataProcessing(array_get($row, 'data'));
                    }

                    $provider->importData($row);
                }

                // $get_index++;
                $data_import_cnt += count($data_import);
                $import_loop_count++;

                // Clear Select table's request session
                $reauestSessionKeys = System::getRequestSessionKeys();
                foreach ($reauestSessionKeys as $reauestSessionKey) {
                    if (strpos($reauestSessionKey, Define::SYSTEM_KEY_SESSION_IMPORT_KEY_VALUE_PREFIX) !== false) {
                        System::clearRequestSession($reauestSessionKey);
                    }
                }
            }
        }

        return [
            'result' => true,
            'data_import_cnt' => $data_import_cnt,
        ];
    }

    public function import($datalist, $options = [])
    {
        // get target data and model list
        $data_imports = [];

        foreach ($datalist as $table_name => &$data) {
            if ($table_name == Define::SETTING_SHEET_NAME) {
                continue;
            }

            // get setting info
            if (array_has($datalist, Define::SETTING_SHEET_NAME)) {
                $settings = $this->getImportTableSetting($datalist[Define::SETTING_SHEET_NAME], $table_name);
                $options['setting'] = $settings;
            }

            //$target_table = $data['custom_table'];
            $provider = $this->getProvider($table_name);
            if (!isset($provider)) {
                continue;
            }

            $dataObject = $provider->getDataObject($data, $options);

            // validate data
            list($data_import, $error_data) = $provider->validateImportData($dataObject);

            // if has error data, return error data
            if (is_array($error_data) && count($error_data) > 0) {
                return response([
                    'result' => false,
                    'toastr' => exmtrans('common.message.import_error'),
                    'errors' => ['import_error_message' => ['type' => 'input', 'message' => implode("\r\n", $error_data)]],
                ], 400);
            }
            $data_imports[] = [
                'provider' => $provider,
                'data_import' => $data_import
            ];
        }

        foreach ($data_imports as $data_import) {
            // execute imoport
            $provider = $data_import['provider'];
            foreach ($data_import['data_import'] as $index => &$row) {
                // call dataProcessing if method exists
                if (method_exists($provider, 'dataProcessing')) {
                    $row['data'] = $provider->dataProcessing(array_get($row, 'data'));
                }

                $provider->importData($row);
            }
        }

        return [
            'result' => true,
            'toastr' => exmtrans('common.message.import_success')
        ];
    }

    /**
     * filter only custom_table or relations datalist.
     */
    public function filterDatalist($datalist)
    {
        // get tablenames
        $table_names = [];
        if (isset($this->custom_table)) {
            $table_names[] = $this->custom_table->table_name;
        }

        foreach ($this->relations as $relation) {
            $table_names[] = $relation->getSheetName();
        }

        $table_names[] = Define::SETTING_SHEET_NAME;

        return collect($datalist)->filter(function ($data, $keyname) use ($table_names) {
            return in_array($keyname, $table_names);
        })->toArray();
    }

    /**
     * get provider
     */
    public function getProvider($keyname)
    {
        // get providers
        if ($keyname == $this->custom_table->table_name) {
            return new Import\DefaultTableProvider([
                'custom_table' => $this->custom_table,
                'primary_key' => $this->primary_key,
                'filter' => $this->filter,
            ]);
        } else {
            // get relations
            foreach ($this->relations as $relation) {
                if ($keyname != $relation->getSheetName()) {
                    continue;
                }
                if ($relation->relation_type == RelationType::MANY_TO_MANY) {
                    return new Import\RelationPivotTableProvider([
                        'relation' => $relation,
                    ]);
                } else {
                    return new Import\DefaultTableProvider([
                        'custom_table' => $relation->child_custom_table,
                        'primary_key' => 'id',
                        'filter' => $this->filter,
                    ]);
                }
            }
        }
    }

    // Import Modal --------------------------------------------------

    /**
     * get import modal endpoint. not contains "import" and "admin"
     */
    public function getImportEndpoint()
    {
        return url_join('data', $this->custom_table->table_name);
    }

    public function getImportHeaderViewName()
    {
        return $this->custom_table->table_view_name;
    }

    /**
     * get primary key list.
     */
    public function getPrimaryKeys()
    {
        // default list
        $keys = getTransArray(Define::CUSTOM_VALUE_IMPORT_KEY, "custom_value.import.key_options");

        // get columns where "unique" options is true.
        $columns = $this->custom_table
            ->custom_columns()
            ->whereIn('options->unique', ["1", 1])
            ->pluck('column_view_name', 'column_name')
            ->toArray();
        // add key name "value.";
        $val_columns = [];
        foreach ($columns as $column_key => $column_value) {
            $val_columns['value.'.$column_key] = $column_value;
        }

        // merge
        $keys = array_merge($keys, $val_columns);

        return $keys;
    }

    /**
     * set_import_modal_items. it sets at form footer
     */
    public function setImportModalItems(&$form)
    {
        $form->hidden('custom_table_name')->default($this->custom_table->table_name);
        $form->hidden('custom_table_suuid')->default($this->custom_table->suuid);
        $form->hidden('custom_table_id')->default($this->custom_table->id);

        return $this;
    }

    protected function getImportTableSetting($settingArray, $table_name)
    {
        if (count($settingArray) <= 2) {
            return [];
        }

        // get header
        $headers = $settingArray[0];

        $bodies = collect(array_slice($settingArray, 2))->filter(function ($setting) use ($table_name) {
            return count($setting) > 0 && $setting[0] == $table_name;
        })->toArray();

        $items = [];
        foreach ($bodies as $body) {
            $null_merge_array = collect(range(1, count($headers)))->map(function () {
                return null;
            })->toArray();
            $body = $body + $null_merge_array;
            $items[] = array_combine($headers, $body);
        }

        return $items;
    }
}
