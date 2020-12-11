<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomView;
use Illuminate\Http\UploadedFile;

class FImportExportTest extends ExmentKitTestCase
{
    /**
     * pre-excecute process before test.
     */
    protected function setUp()
    {
        parent::setUp();
        $this->login();
    }

    /**
     * test import customvalue data csv
     */
    public function testImportCsv()
    {
        $this->skipTempTest('This test code is in process.');

        $file_path = exment_package_path("tests/tmpfile/Browser/custom_value_edit_all_1.csv");
        $file = new UploadedFile($file_path, 'custom_value_edit_all_1.csv');

        // check config update
        $response = $this->post(admin_url('data/custom_value_edit_all/import'), [
            'select_primary_key' => 'id',
            'custom_table_file' => $file,
        ]);

        $content = $response->response->getContent();
    }

    /**
     * test import customvalue data xlsx
     */
    public function testImportXlsx()
    {
        $this->skipTempTest('This test code is in process.');

        $file_path = exment_package_path("tests/tmpfile/Browser/custom_value_edit_all_2.xlsx");
        $file = new UploadedFile($file_path, 'custom_value_edit_all_2.xlsx');

        // check config update
        $response = $this->post(admin_url('data/custom_value_edit_all/import'), [
            'select_primary_key' => 'id',
            'custom_table_file' => $file,
        ]);

        $content = $response->response->getContent();
    }

    /**
     * test export customvalue data as csv
     */
    public function testExportCsvAll()
    {
        $this->skipTempTest('This test code is in process.');

        $custom_view = CustomView::where('view_view_name', "custom_value_edit_all-view-or")->first();

        // check config update
        $response = $this->get(admin_urls_query('data/custom_value_edit_all', [
            'action' => 'export',
            'format' => 'csv',
            '_export_' => 'all',
            'view' => $custom_view->suuid,
            'page' => 1,
        ]));

        $content = $response->response->getContent();
        if(is_json($content)){
            $json = json_decode($content, true);

        }
    }

    /**
     * test export customvalue data as xlsx
     */
    public function testExportXlsx()
    {
        $this->skipTempTest('This test code is in process.');
    }

    /**
     * test export customvalue view data as csv
     */
    public function testExportCsvView()
    {
        $this->skipTempTest('This test code is in process.');
    }

    /**
     * test export customvalue view data as xlsx
     */
    public function testExportXlsxView()
    {
        $this->skipTempTest('This test code is in process.');
    }

    /**
     * test export customvalue data as csv with login user
     */
    public function testExportCsvLoginUser()
    {
        $this->skipTempTest('This test code is in process.');
    }

    /**
     * test export customvalue data as xlsx with login user
     */
    public function testExportXlsxLoginUser()
    {
        $this->skipTempTest('This test code is in process.');
    }
}
