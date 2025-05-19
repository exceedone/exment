<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

abstract class FileImportTestBase extends FeatureTestBase
{
    use ImportTrait;
    use TestTrait;

    /**
     * full path stored export files.
     *
     * @var string
     */
    protected $dirpath;

    /**
     * @return mixed
     */
    abstract protected function getImportPath();

    /**
     * @return mixed
     */
    abstract protected function getSourceFilePath();

    /**
     * @param string $target_name
     * @return mixed
     */
    abstract protected function getCommand(string $target_name);

    /**
     * @param string $file_path
     * @param bool $isCsv
     * @return mixed
     */
    abstract protected function assertFileTest(string $file_path, bool $isCsv);

    /**
     * @return void
     */
    protected function init()
    {
        try {
            $this->initAllTest();
            $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
            $import_path = $this->getImportPath();
            \Exment::makeDirectory($import_path);

            $sourceDir = $this->getSourceFilePath();
            $dirs = scandir($sourceDir);
            foreach ($dirs as $dir) {
                if ($dir == '.' ||$dir == '..') {
                    continue;
                }
                $sourceFullDir = path_join_os($sourceDir, $dir);
                $importFullDir = path_join_os($import_path, $dir);
                if (\File::exists($importFullDir)) {
                    continue;
                }
                \File::copyDirectory($sourceFullDir, $importFullDir);
            }
        } catch (\Exception $ex) {
        }
    }

    /**
     * Import.
     * Format: csv
     *
     * @return void
     */
    public function testImportSuccessCsv()
    {
        $this->_testImport('import_csv', true, true);
    }

    /**
     * Import.
     * Format: xlsx
     * Contains multiple file
     *
     * @return void
     */
    public function testImportSuccessXlsx()
    {
        $this->_testImport('import_xlsx', true, false);
    }



    /**
     * Execute test
     *
     * @param string $target_name
     * @param boolean $isSuccess
     * @return void
     */
    protected function _testImport(string $target_name, bool $isSuccess, bool $isCsv)
    {
        $this->init();

        $console = $this->getCommand($target_name);
        $console->expectsOutput(exmtrans('command.import.file_count')."1");
        $console->assertExitCode($isSuccess ? 0 : -1);
        $console->run();

        if ($isSuccess) {
            $file_path = path_join_os($this->getImportPath(), $target_name);
            $this->assertFileTest($file_path, $isCsv);
        }
    }


    /**
     * @param mixed $fileColumns
     * @param array<mixed> $array
     * @return Model\File|void|null
     */
    protected function getMatchedPath($fileColumns, $array)
    {
        foreach (toArray($fileColumns) as $fileColumn) {
            $fileInfo = Model\File::getData($fileColumn);
            if (isMatchString($array[3], $fileInfo->filename)) {
                return $fileInfo;
            }
        }

        $this->assertTrue(false, 'Not matched file path');
    }
}
