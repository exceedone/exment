<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

class ImportExportTest extends UnitTestBase
{
    use TestTrait;

    /**
     * full path stored export files.
     *
     * @var string
     */
    protected $dirpath;

    protected function init(bool $export, $target_name = null)
    {
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        if ($export) {
            $this->dirpath = storage_path(path_join_os('app', 'export', 'unittest'));
            if (\File::exists($this->dirpath)) {
                \File::deleteDirectory($this->dirpath);
            }
            \File::makeDirectory($this->dirpath, 0755, true);
        } else {
            $import_path = storage_path(path_join_os('app', 'import', 'unittest'));
            if (\File::exists($import_path)) {
                \File::deleteDirectory($import_path);
            }
            \File::makeDirectory($import_path, 0755, true);
            $source_path = exment_package_path("tests/tmpfile/Unit/$target_name");
            \File::copyDirectory($source_path, $import_path);
            $this->dirpath = 'unittest';
        }
    }

    public function testExportCsv()
    {
        $table_name = 'custom_value_edit_all';

        $this->_testExport([
            'table_name' => $table_name,
        ]);
    }

    public function testExportXlsx()
    {
        $table_name = 'custom_value_edit_all';

        $this->_testExport([
            'table_name' => $table_name,
            '--format' => 'xlsx',
        ]);
    }

    public function testExportCsvView()
    {
        $table_name = 'custom_value_edit_all';

        $custom_view = CustomView::where('view_view_name', "$table_name-view-odd")->first();

        $this->_testExport([
            'table_name' => $table_name,
            '--action' => 'view',
            '--view' => $custom_view,
        ]);
    }

    public function testExportXlsxView()
    {
        $table_name = 'custom_value_edit_all';

        $custom_view = CustomView::where('view_view_name', "$table_name-view-and")->first();

        $this->_testExport([
            'table_name' => $table_name,
            '--format' => 'xlsx',
            '--action' => 'view',
            '--view' => $custom_view,
        ]);
    }

    public function testExportCsvViewPage()
    {
        $table_name = 'custom_value_edit_all';

        $custom_view = CustomView::where('view_view_name', "$table_name-view-odd")->first();

        $this->_testExport([
            'table_name' => $table_name,
            '--action' => 'view',
            '--view' => $custom_view,
            '--type' => 'page',
            '--page' => 2,
        ]);
    }

    public function testExportXlsxViewPage()
    {
        $table_name = 'custom_value_edit_all';

        $custom_view = CustomView::where('view_view_name', "$table_name-view-or")->first();

        $this->_testExport([
            'table_name' => $table_name,
            '--format' => 'xlsx',
            '--action' => 'view',
            '--view' => $custom_view,
            '--type' => 'page',
            '--page' => 2,
        ]);
    }

    public function testExportCsvWithSetting()
    {
        $table_name = 'custom_value_edit_all';

        $custom_view = CustomView::where('view_view_name', "$table_name-view-odd")->first();

        $this->_testExport([
            'table_name' => $table_name,
            '--action' => 'view',
            '--view' => $custom_view,
            '--add_setting' => 1,
        ]);
    }

    public function testExportXlsxWithRelation()
    {
        $table_name = 'parent_table';

        $this->_testExport([
            'table_name' => $table_name,
            '--format' => 'xlsx',
            '--add_relation' => 1,
        ]);
    }

    public function testChunkExportDefault()
    {
        $table_name = 'custom_value_edit_all';

        $this->_testChunkExport([
            'table_name' => $table_name,
        ], [1]);
    }

    public function testChunkExportRange()
    {
        $table_name = 'custom_value_edit_all';

        $this->_testChunkExport([
            'table_name' => $table_name,
            '--start' => 2,
            '--end' => 4,
            '--count' => 20,
            '--seqlength' => 3,
            '--format' => 'xlsx',
        ], ['002', '003', '004']);
    }

    public function testImport()
    {
        $this->_testImport('import_test_1');
    }

    public function testImportMulti()
    {
        $this->_testImport('import_test_2');
    }

    protected function _testExport(array $params)
    {
        $this->init(true);

        $params = array_merge(
            [
                '--dirpath' => $this->dirpath,
                '--format' => 'csv',
            ], 
            $params
        );

        $result = \Artisan::call('exment:export', $params);

        $this->assertEquals($result, 0);

        $file_path = path_join($params['--dirpath'], $params['table_name'] . '.' . $params['--format']);
        $this->assertTrue(\File::exists($file_path));
        $this->assertTrue(\File::size($file_path) > 0);
    }

    protected function _testChunkExport(array $params, array $numRange)
    {
        $this->init(true);

        $params = array_merge(
            [
                '--dirpath' => $this->dirpath,
                '--format' => 'csv',
            ], 
            $params
        );

        $result = \Artisan::call('exment:chunkexport', $params);

        $this->assertEquals($result, 0);

        foreach ($numRange as $num) {
            $file_path = path_join($params['--dirpath'], $params['table_name'] . ".$num." . $params['--format']);
            $this->assertTrue(\File::exists($file_path));
            $this->assertTrue(\File::size($file_path) > 0);
        }
    }

    protected function _testImport($target_name)
    {
        $this->init(false, $target_name);

        $result = \Artisan::call('exment:import', [
            'dir' => $this->dirpath
        ]);

        $this->assertEquals($result, 0);
    }
}
