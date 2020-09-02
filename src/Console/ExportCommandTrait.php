<?php

namespace Exceedone\Exment\Console;

use Encore\Admin\Grid;
use Illuminate\Console\Command;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Services\DataImportExport;

trait ExportCommandTrait
{
    protected function getParametersCommon()
    {
        $table_name = $this->argument("table_name");

        if (!isset($table_name)) {
            throw new \Exception('parameter table name is empty');
        }

        $custom_table = CustomTable::getEloquent($table_name);

        if (!isset($custom_table)) {
            throw new \Exception('parameter table name error : ' . $table_name);
        }

        $options = [];
        // get parameters
        $options['action'] = $this->option("action");
        $options['format'] = $this->option("format");
        $options['view'] = $this->option("view");
        $options['dirpath'] = $this->option("dirpath");

        if (!\in_array($options['action'], ['default', 'view'])) {
            throw new \Exception('optional parameter action error : ' . $options['action']);
        }

        if (!\in_array($options['format'], ['csv', 'xlsx'])) {
            throw new \Exception('optional parameter format error : ' . $options['format']);
        }

        // set view info
        if (isset($options['view'])) {
            $custom_view = CustomView::getEloquent($options['view']);

            if (!isset($custom_view)) {
                throw new \Exception('optional parameter view error : ' . $options['view']);
            }
            $options['view'] = $custom_view;
        }

        if ($options['action'] == 'view') {
            if (!isset($options['view'])) {
                // get all data view
                $options['view'] = CustomView::getAllData($custom_table);
            }
        }

        if (isset($options['dirpath'])) {
            if (!\File::isDirectory($options['dirpath'])) {
                throw new \Exception('optional parameter dirpath error : ' . $options['dirpath']);
            }
        } else {
            // get default directory full path
            $options['dirpath'] = storage_path(path_join_os('app', 'export', date('YmdHis')));
            // if default is not exists, make directory
            if (!\File::exists($options['dirpath'])) {
                \File::makeDirectory($options['dirpath'], 0755, true);
            }
        }

        return $options;
    }
}
