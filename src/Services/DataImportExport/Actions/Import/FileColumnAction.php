<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Import;

use Exceedone\Exment\Services\DataImportExport\Providers\Import;

/**
 * File column(image, file) import action
 */
class FileColumnAction implements ActionInterface
{
    /**
     * File directory full path.
     *
     * @var string
     */
    protected $fileDirFullPath;

    /**
     * target custom table
     */
    protected $custom_table;

    public function __construct($args = [])
    {
        $this->custom_table = array_get($args, 'custom_table');
        $this->fileDirFullPath = array_get($args, 'fileDirFullPath');
    }


    public function importChunk($datalist, $options = [])
    {
        $messages = [];
        $data_import_cnt = 0;

        foreach ($datalist as &$data) {
            $table_name = $this->custom_table->table_name;
            $provider = $this->getProvider($table_name);
            if (!isset($provider)) {
                continue;
            }

            $import_loop_count = 0;
            $take = $options['take'] ?? 1000;
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
                        $options['row_start'] ?? null,
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
            }
        }

        return [
            'result' => true,
            'data_import_cnt' => $data_import_cnt,
        ];
    }


    public function import($datalist, $options = [])
    {
    }

    /**
     * filter only custom_table or relations datalist.
     */
    public function filterDatalist($datalist)
    {
        return $datalist;
    }

    /**
     * get provider
     */
    public function getProvider($table_name)
    {
        return new Import\FileColumnProvider([
            'custom_table' => $this->custom_table,
            'fileDirFullPath' => $this->fileDirFullPath,
        ]);
    }
}
