<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\DataImportExport;
use \File;

class ImportCommand extends Command
{
    use CommandTrait, ImportTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:import {dir}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import Exment data';

    /**
     * full path stored bulk insert files.
     *
     * @var string
     */
    protected $directory;

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

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            // get target directory (argument)
            $dir = $this->argument("dir");

            // check if parameter directory include separater
            if (preg_match('/[\\\\\/]/', $dir)) {
                throw new \Exception('parameter directory error : ' . $dir);
            }

            // get directory full path
            $this->directory = storage_path(path_join('app', 'import', $dir));

            // get all csv file names in target directory
            $files = $this->getFiles('csv,xlsx');

            $this->line(exmtrans('command.import.file_count').count($files));

            foreach ($files as $index => $file) {
                $file_name = $file->getFileName();
                $this->line(($index + 1) . exmtrans('command.import.file_info', $file_name));

                $table_name = file_ext_strip($file_name);
                $table_name = preg_replace('/^\d+#/', '', $table_name);
                $format = file_ext($file_name);
    
                $custom_table = CustomTable::getEloquent($table_name);
                if (!isset($custom_table)) {
                    $this->line(exmtrans('command.import.error_info'));
                    continue;
                }
    
                $service = (new DataImportExport\DataImportExportService())
                    ->filebasename($custom_table->table_name)
                    ->importAction(new DataImportExport\Actions\Import\CustomTableAction(
                        [
                            'custom_table' => $custom_table,
                        ]
                    ))
                    ->format($format);

                $result = $service->importBackground($file->getRealPath(), [
                    'checkCount' => false,  // whether checking count
                    'take' => 100           // if set, taking data count
                ]);

                $message = array_get($result, 'message');
                if (!empty($message)) {
                    $this->line($message);
                }
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return -1;
        }

        return 0;
    }
}
