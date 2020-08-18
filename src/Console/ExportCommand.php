<?php

namespace Exceedone\Exment\Console;

use Encore\Admin\Grid;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Services\DataImportExport;
use \File;

class ExportCommand extends Command
{
    use CommandTrait, ImportTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:export {table_name} {--action=default} {--type=all} {--page=1} {--format=csv} {--view=} {--dirpath=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export Exment data';

    /**
     * full path stored export files.
     *
     * @var string
     */
    protected $dirpath;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->initExmentCommand();
    }

    protected function getParameters()
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
        $options['type'] = $this->option("type");
        $options['page'] = $this->option("page");
        $options['format'] = $this->option("format");
        $options['view'] = $this->option("view");
        $options['dirpath'] = $this->option("dirpath");

        if (!\in_array($options['action'], ['default', 'view'])) {
            throw new \Exception('parameter action error : ' . $options['action']);
        }

        if (!\in_array($options['type'], ['all', 'page'])) {
            throw new \Exception('parameter type error : ' . $options['type']);
        }

        if (!preg_match("/^[0-9]+$/", $options['page'])) {
            throw new \Exception('parameter page error : ' . $options['page']);
        }

        if (!\in_array($options['format'], ['csv', 'xlsx'])) {
            throw new \Exception('parameter format error : ' . $options['format']);
        }

        if ($options['action'] == 'view') {
            if (!isset($options['view'])) {
                // get all data view
                $custom_view = $custom_table->custom_views()->where('view_kind_type', ViewKindType::ALLDATA)->first();
            } else {
                $custom_view = CustomView::getEloquent($options['view']);
            }
            if (!isset($custom_view)) {
                throw new \Exception('parameter view error : ' . $options['view']);
            }
            $options['view'] = $custom_view;
        }

        if (isset($options['dirpath'])) {
            if (!\File::isDirectory($options['dirpath'])) {
                throw new \Exception('parameter dirpath error : ' . $options['dirpath']);
            }
        } else {
            // get default directory full path
            $options['dirpath'] = storage_path(path_join('app', 'export', date('YmdHis')));
            // if default is not exists, make directory
            if (!\File::exists($options['dirpath'])) {
                \File::makeDirectory($options['dirpath'], 0755, true);
            }
        }

        $options['filepath'] = path_join($options['dirpath'], $table_name.'.'.$options['format']);

        return [$custom_table, $options];
    }
    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            // get parameters
            list($custom_table, $options) = $this->getParameters();
            $classname = getModelName($custom_table);
            $grid = new Grid(new $classname);
    
            $service = (new DataImportExport\DataImportExportService())
                ->exportAction(new DataImportExport\Actions\Export\CustomTableAction(
                    [
                        'custom_table' => $custom_table,
                        'grid' => $grid
                    ]
                ))->viewExportAction(new DataImportExport\Actions\Export\SummaryAction(
                    [
                        'custom_table' => $custom_table,
                        'custom_view' => $options['view'],
                    ]
                ))
                ->format($options['format']);
            
            $result = $service->exportBackground($options);

        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return -1;
        }

        return 0;
    }
}
