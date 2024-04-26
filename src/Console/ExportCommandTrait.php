<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Services\DataImportExport;

trait ExportCommandTrait
{
    protected function getParametersCommon()
    {
        /** @var null|string $table_name */
        $table_name = $this->argument("table_name");

        if ($table_name === null) {
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
            if (!\File::exists($options['dirpath'])) {
                \Exment::makeDirectory($options['dirpath']);
            } elseif (!\File::isDirectory($options['dirpath'])) {
                throw new \Exception('optional parameter dirpath error : ' . $options['dirpath']);
            }
        } else {
            // get default directory full path
            $options['dirpath'] = storage_path(path_join_os('app', 'export', date('YmdHis')));
            // if default is not exists, make directory
            \Exment::makeDirectory($options['dirpath']);
        }

        return $options;
    }

    /**
     * Get export action
     *
     * @param CustomTable $custom_table
     * @param \Encore\Admin\Grid $grid
     * @param array $options
     * @return \Exceedone\Exment\Services\DataImportExport\Actions\Export\ActionInterface
     */
    protected function getExportAction(CustomTable $custom_table, $grid, array $options)
    {
        $custom_view = array_get($options, 'view');

        // if summary view, return SummaryAction
        if (isMatchString(array_get($options, 'action'), 'view') && isset($custom_view)) {
            if (isMatchString($custom_view->view_kind_type, ViewKindType::AGGREGATE)) {
                // append summary query
                $summary_grid = new \Exceedone\Exment\DataItems\Grid\SummaryGrid($custom_table, $custom_view);
                $summary_grid->setSummaryGrid($grid);
                $summary_grid->setGrid($grid);

                return new DataImportExport\Actions\Export\SummaryAction(
                    [
                        'grid' => $grid,
                        'custom_table' => $custom_table,
                        'custom_view' => $custom_view,
                    ]
                );
            } else {
                return new DataImportExport\Actions\Export\ViewAction(
                    [
                        'custom_table' => $custom_table,
                        'custom_view' => $custom_view,
                        'grid' => $grid,
                    ]
                );
            }
        }


        return new DataImportExport\Actions\Export\CustomTableAction(
            [
                'custom_table' => $custom_table,
                'grid' => $grid,
                'add_setting' => boolval(array_get($options, 'add_setting', false)),
                'add_relation' => boolval(array_get($options, 'add_relation', false)),
            ]
        );
    }
}
