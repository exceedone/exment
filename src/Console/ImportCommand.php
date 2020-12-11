<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\DataImportExport;

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

                // continue prefix '~' file
                if (strpos($file_name, '~') !== false) {
                    continue;
                }

                $this->line(($index + 1) . exmtrans('command.import.file_info', $file_name));

                $format = file_ext($file_name);
                $custom_table = $this->getTableFromFile($file_name);
                if (!isset($custom_table)) {
                    $this->error(exmtrans('command.import.error_info') . exmtrans('command.import.error_table', $file_name));
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

                // Execute import. Show message executes in service.
                $result = $service->importBackground($this, $file_name, $file->getRealPath(), [
                    'checkCount' => false,  // whether checking count
                    'take' => 100           // if set, taking data count
                ]);
                
                if (boolval($result['result'] ?? true)) {
                    $this->line(($index + 1) . exmtrans('command.import.success_message', $file_name, array_get($result, 'data_import_cnt')));
                }
            }
        } catch (\Exception $e) {
            \Log::error($e);
            $this->error($e->getMessage());
            return -1;
        }

        return 0;
    }

    /**
     * Get table from file name.
     * Support such as:
     *     information.csv
     *     information#001.csv
     *     information.001.csv
     *
     * @param string  $file_name
     * @return CustomTable|null
     */
    protected function getTableFromFile(string $file_name) : ?CustomTable
    {
        $table_name = file_ext_strip($file_name);
        // directry same name
        if (!is_null($custom_table = CustomTable::getEloquent($table_name))) {
            return $custom_table;
        }

        // If contains "#" in file name, throw exception
        if(strpos($table_name, '#') !== false){
            throw new \Exception('File name that conatains "#" not supported over v3.8.0.');
        }

        // loop for regex
        $regexes = ['(?<table_name>.+)\\.\d+', '\d+\\.(?<table_name>.+)'];
        foreach ($regexes as $regex) {
            $match_num = preg_match('/' . $regex . '/u', $table_name, $matches);
            if ($match_num > 0 && !is_null($custom_table = CustomTable::getEloquent($matches['table_name']))) {
                return $custom_table;
            }
        }

        return null;
    }
}
