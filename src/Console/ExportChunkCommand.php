<?php

namespace Exceedone\Exment\Console;

use Encore\Admin\Grid;
use Illuminate\Console\Command;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Services\DataImportExport;

class ExportChunkCommand extends Command
{
    use CommandTrait;
    use ExportCommandTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:chunkexport {table_name} {--action=default} {--start=1} {--end=1000} {--count=1000} {--seqlength=1}  {--delimiter=}  {--format=csv} {--view=} {--dirpath=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Chunk export Exment data';

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
        /** @var null|string $table_name */
        $table_name = $this->argument("table_name");

        if ($table_name === null) {
            throw new \Exception('parameter table name is empty');
        }

        $custom_table = CustomTable::getEloquent($table_name);

        if (!isset($custom_table)) {
            throw new \Exception('parameter table name error : ' . $table_name);
        }

        $options = $this->getParametersCommon();

        // get parameters
        $options['count'] = $this->option("count");
        $options['start'] = $this->option("start");
        $options['end'] = $this->option("end");
        $options['seqlength'] = $this->option("seqlength");
        $options['delimiter'] = $this->option("delimiter") ?? '.';

        if ($options['count']) {
            if (!preg_match("/^[0-9]+$/", $options['count'])) {
                throw new \Exception('optional parameter count error : ' . $options['count']);
            }
        } else {
            $options['count'] = 1000;
        }

        if ($options['start']) {
            if (!preg_match("/^[0-9]+$/", $options['start'])) {
                throw new \Exception('optional parameter start error : ' . $options['start']);
            }
        } else {
            $options['start'] = 1;
        }
        if ($options['end']) {
            if (!preg_match("/^[0-9]+$/", $options['end'])) {
                throw new \Exception('optional parameter end error : ' . $options['end']);
            }
        } else {
            $options['end'] = 1000;
        }

        if ($options['seqlength']) {
            if (!preg_match("/^[0-9]+$/", $options['seqlength'])) {
                throw new \Exception('optional parameter seqlength error : ' . $options['seqlength']);
            }
        } else {
            $options['seqlength'] = 1;
        }

        $options['breakIfEmpty'] = true;
        return [$custom_table, $options];
    }
    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        try {
            // get parameters
            list($custom_table, $options) = $this->getParameters();
            $classname = getModelName($custom_table);
            $message = null;

            $executeCount = 0;
            /** @phpstan-ignore-next-line Expression on left side of ?? is not nullable. */
            for ($i = $options['start'] ?? 1; $i <= $options['end'] ?? 1000; $i++) {
                $grid = new Grid(new $classname());
                // set data get range
                $grid->model()->setPerPageArguments([$options['count'] ?? 1000, ['*'], 'page', $i])
                    ->disableHandleInvalidPage();
                if (isset($options['view']) && $options['view'] instanceof CustomView) {
                    $options['view']->filterSortModel($grid->model());
                }

                $seq = str_pad($i, $options['seqlength'], 0, STR_PAD_LEFT);

                $service = (new DataImportExport\DataImportExportService())
                ->exportAction($this->getExportAction($custom_table, $grid, $options))
                ->format($options['format'])
                ->filebasename("{$custom_table->table_name}{$options['delimiter']}{$seq}");

                $result = $service->exportBackground($options);

                if (empty($message)) {
                    $message = array_get($result, 'message');
                }

                if (array_get($result, 'status') !== 0) {
                    break;
                }

                $executeCount++;
                if ($executeCount >= 1000) {
                    break;
                }
            }

            if (!empty($message)) {
                $this->line($message);
            }
        } catch (\Exception $e) {
            \Log::error($e);
            $this->error($e->getMessage());
            return -1;
        }

        return 0;
    }
}
