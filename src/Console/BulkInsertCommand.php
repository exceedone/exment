<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use \File;

class BulkInsertCommand extends Command
{
    use CommandTrait;

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

            // convert all csv files in target folder
            $path = $this->convertFiles($dir);

            // import tsv file to database table
            $this->importTsv($path);

            // delete working folder
            File::deleteDirectory($path);
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return -1;
        }

        return 0;
    }

    /**
     * get file names in target folder (filter extension)
     *
     */
    private function getFiles($dir, $ext = 'tsv', $include_sub = false)
    {
        // get files in target folder
        if ($include_sub) {
            $files = File::allFiles($dir);
        } else {
            $files = File::files($dir);
        }
        // filter files by extension
        $files = array_filter($files, function ($file) use ($ext) {
            return preg_match('/.+\.'.$ext.'$/i', $file);
        });
        return $files;
    }

    /**
     * convert all csv files in target folder into tsv files
     *
     */
    private function convertFiles($dir, $include_sub = false)
    {

        // check if directory is exists
        if (!File::isDirectory($dir)) {
            throw new \Exception('Not found directory : ' . $dir);
        }

        // get all csv file names in target directory
        $files = $this->getFiles($dir, 'csv', $include_sub);

        // get temp directory full path
        $tempdir = storage_path('app/tmp/bulkins/' . date('YmdHis'));

        // create temp directory if not exists
        if (!File::exists($tempdir)) {
            File::makeDirectory($tempdir, 0755, true);
        }

        // convert csv file to tsv file
        $this->convertTsv($tempdir, $files);

        return $tempdir;
    }

    /**
     * convert csv files to tsv files
     *
     */
    private function convertTsv($tempdir, $files)
    {
        foreach ($files as $file) {
            // 1:table_name, (2:sequence number) , 3:extension
            $targets = explode('.', $file->getFilename());
            // get physical table name
            $tablename = getDBTableName($targets[0]);

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
                    continue;
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
            $output = new \SplFileObject(path_join($tempdir, $tsvname), 'w');
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
        }
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
        $keys = preg_grep('/^'.$field.'(:.+)?$/i', $header);
        if (count($keys) == 0) {
            return '';
        }
        $ary = [];
        foreach ($keys as $key => $value) {
            $data = array_key_exists($key, $line)? $line[$key] : '';
            $targets = explode(':', $value);
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
     * @param string temporary folder path stored work tsv files
     */
    private function importTsv($dir)
    {
        // load table data from tsv file
        foreach ($this->getFiles($dir) as $file) {
            $targets = explode('.', $file->getFilename());
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
}
