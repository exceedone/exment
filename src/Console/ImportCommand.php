<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Services\DataImportExport;

class ImportCommand extends Command
{
    use CommandTrait;
    use ImportTrait;

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
     * @return int
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
}
