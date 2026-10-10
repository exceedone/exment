<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Tests\TestTrait;

class DocumentImportTest extends FileImportTestBase
{
    use ImportTrait;
    use TestTrait;

    protected function getImportPath()
    {
        return storage_path(path_join_os('app', 'document-import'));
    }
    protected function getSourceFilePath()
    {
        return exment_package_path("tests/tmpfile/Feature/document_import");
    }
    protected function getCommand(string $target_name)
    {
        return $this->artisan("exment:document-import $target_name");
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
            /** @var CustomTable $custom_table */
            $custom_table = CustomTable::getEloquent($baseName);

            $fileArray = $isCsv ? $this->_getCsvArray($file->getPathName()) : $this->_getXlsxArray($file->getPathName())[$baseName];

            foreach ($fileArray as $index => $array) {
                if ($index <= 1) {
                    continue;
                }

                // get custom value
                /** @var \Exceedone\Exment\Model\CustomValue $custom_value */
                $custom_value = $custom_table->getValueModel($array[0]);

                // get documents
                $documents = $custom_value->getDocuments()
                    ->map(function ($document) {
                        /** @var Model\File $document */
                        return array_get($document->value, 'file_uuid');
                    });
                $this->assertTrue(!is_nullorempty($documents));
                /** @var \Exceedone\Exment\Model\File $fileInfo */
                $fileInfo = $this->getMatchedPath($documents, $array);

                // check file
                $storage_file_path = path_join_os($file_path, 'documents', $array[1]);
                $disk = \Storage::disk(Define::DISKNAME_ADMIN);
                $this->assertFileEquals(getFullpath($fileInfo->path, $disk), $storage_file_path);
            }
        }
    }

    protected function getMatchedPath($documents, $array)
    {
        // @phpstan-ignore-next-line
        foreach (toArray($documents) as $document) {
            /** @var \Exceedone\Exment\Model\File $fileInfo */
            $fileInfo = Model\File::getData($document);
            if (isMatchString($array[2], $fileInfo->filename)) {
                return $fileInfo;
            }
        }

        $this->assertTrue(false, 'Not matched file path');
    }
}
