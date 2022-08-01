<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Exceedone\Exment\Model\Define;
use File;

class BulkInsertCommand extends Command
{
    use CommandTrait;
    use ImportTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:bulkinsert {dir}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Insert large amount of data from tsv file placed in specified folder';

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
            $this->directory = storage_path(path_join('app', 'bulkinsert', $dir));

            // get all csv file names in target directory
            $files = $this->getFiles('csv');

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

    /**
     * convert all csv files in target folder into tsv files
     *
     */
    private function convertFile($file, $include_sub = false)
    {

        // check if directory is exists
        if (!File::isDirectory($this->directory)) {
            throw new \Exception('Not found directory : ' . $this->directory);
        }

        // get temp directory full path
        $tempdir = getFullpath(date('YmdHis'), Define::DISKNAME_ADMIN_TMP);

        // create temp directory if not exists
        \Exment::makeDirectory($tempdir);

        // convert csv file to tsv file
        $outputpath = $this->convertTsv($tempdir, $file);

        return $outputpath;
    }

    /**
     * convert csv files to tsv files
     *
     */
    private function convertTsv($tempdir, $file)
    {
        // 1:table_name, (2:sequence number) , 3:extension
        $targets = explode('.', $file->getFilename());
        // get physical table name
        $tablename = getDBTableName($targets[0]);

        $tsvname = null;
        switch (count($targets)) {
            case 2:
                $tsvname = $tablename . '.tsv';
                break;
            case 3:
                // if file has sequence, format number for sort
                $tsvname = $tablename . '.' . str_pad($targets[1], 10, '0', STR_PAD_LEFT) . '.tsv';
                break;
            default:
                $this->warn('invalid file name (skipped) : ' . $file->getFilename());
                break;
        }
        // get table all column names
        $columns = Schema::getColumnListing($tablename);

        // open input file as csv
        $fileobj = $file->openFile();
        $fileobj->setFlags(
            \SplFileObject::READ_CSV |
            \SplFileObject::READ_AHEAD |
            \SplFileObject::SKIP_EMPTY
        );

        // get locale
        $locales = explode('.', setlocale(LC_CTYPE, '0'));
        // set locale UTF-8 (if sjis)
        if (count($locales) >= 2 && $locales[1] == '932') {
            setlocale(LC_CTYPE, $locales[0] . '.UTF-8');
        }
        // create output file as tsv
        $outputpath = path_join($tempdir, $tsvname);
        $output = new \SplFileObject($outputpath, 'w');
        $output->setCsvControl("\t");

        $i = 0;
        $header = [];
        foreach ($fileobj as $line) {
            if ($i == 0) {
                // store header data
                $header = $line;
                // write all column names
                $records = $columns;
            } else {
                $records = $this->getTsvLine($columns, $header, $line);
            }

            $output->fputcsv($records);
            $i++;
        }

        return $outputpath;
    }

    /**
     * edit tsv line
     *
     */
    private function getTsvLine($columns, $header, $line)
    {
        return array_map(function ($field) use ($header, $line) {
            $data = $this->getTsvData($field, $header, $line);
            // set default data when empty
            if (empty($data)) {
                switch ($field) {
                    case 'suuid':
                        $data = short_uuid();
                        break;
                }
            }
            return $data;
        }, $columns);
    }

    /**
     * edit tsv data item
     *
     */
    private function getTsvData($field, $header, $line)
    {
        // extract header item that matches the field name
        $keys = preg_grep('/^'.$field.'(\..+)?$/i', $header);
        if (count($keys) == 0) {
            return null;
        }
        $ary = [];
        foreach ($keys as $key => $value) {
            $data = array_key_exists($key, $line) ? $line[$key] : '';
            $targets = explode('.', $value);
            if (count($targets) == 1) {
                return $data;
            }
            $ary[trim($targets[1])] = $data;
        }
        // If multiple data match, aggregate in JSON
        return json_encode($ary);
    }

    /**
     * insert table data from tsv files.
     *
     * @param string $file temporary file path stored work tsv files
     */
    private function importTsv($file)
    {
        $file = new \SplFileInfo($file);
        // load table data from tsv file
        $targets = explode('.', $file->getFileName());
        $cmd =<<<__EOT__
        LOAD DATA local INFILE '%s' 
        INTO TABLE %s 
        CHARACTER SET 'UTF8' 
        FIELDS TERMINATED BY '\t' 
        OPTIONALLY ENCLOSED BY '\"' 
        ESCAPED BY '\"' 
        LINES TERMINATED BY '\\n' 
        IGNORE 1 LINES 
        SET created_at = NOW(),
            updated_at = NOW(),
            deleted_at = nullif(deleted_at, '0000-00-00 00:00:00'),
            created_user_id = nullif(created_user_id, 0),
            updated_user_id = nullif(updated_user_id, 0),
            deleted_user_id = nullif(deleted_user_id, 0),
            parent_id = nullif(parent_id, 0)
__EOT__;
        $query = sprintf($cmd, addslashes($file->getPathName()), $targets[0]);
        $cnt = \DB::connection()->getpdo()->exec($query);
    }
}
