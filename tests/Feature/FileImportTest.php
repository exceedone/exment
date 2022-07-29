<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Tests\TestTrait;

class FileImportTest extends FileImportTestBase
{
    use ImportTrait;
    use TestTrait;

    protected function getImportPath()
    {
        return storage_path(path_join_os('app', 'file-import'));
    }
    protected function getSourceFilePath()
    {
        return exment_package_path("tests/tmpfile/Feature/file_import");
    }
    protected function getCommand(string $target_name)
    {
        return $this->artisan("exment:file-import $target_name");
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
     * File import test
     *
     * @param string $file_path
     * @param boolean $isCsv
     * @return void
     */
    protected function assertFileTest(string $file_path, bool $isCsv)
    {
        $files = \File::files($file_path);
        foreach ($files as $file) {
            $baseName = pathinfo($file, PATHINFO_FILENAME);
            if (strpos($baseName, '~') === 0) {
                continue;
            }
            $custom_table = CustomTable::getEloquent($baseName);

            $fileArray = $isCsv ? $this->_getCsvArray($file->getPathName()) : $this->_getXlsxArray($file->getPathName())[$baseName];

            foreach ($fileArray as $index => $array) {
                if ($index <= 1) {
                    continue;
                }

                // get custom value
                $custom_value = $custom_table->getValueModel($array[0]);

                // get column's value
                $fileColumns = $custom_value->getValue($array[1]);
                $this->assertTrue(!is_nullorempty($fileColumns));
                $fileInfo = $this->getMatchedPath($fileColumns, $array);

                // check file
                $storage_file_path = path_join_os($file_path, 'files', $array[2]);
                $disk = \Storage::disk(Define::DISKNAME_ADMIN);
                $this->assertFileEquals(getFullpath($fileInfo->path, $disk), $storage_file_path);
            }
        }
    }
}
