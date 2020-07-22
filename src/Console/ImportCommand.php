<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Exceedone\Exment\Model\Define;
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

            $this->line("該当ファイル数：".count($files));

            $path = null;
            foreach ($files as $index => $file) {
                $this->line(($index + 1) . "件目 実施開始 ファイル:{$file->getFileName()}");

                // convert all csv files in target folder
                $path = $this->convertFile($file);

                // import tsv file to database table
                $this->importTsv($path);
            }

            // delete working folder
            File::deleteDirectory(dirname($path));
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return -1;
        }

        return 0;
    }
}
