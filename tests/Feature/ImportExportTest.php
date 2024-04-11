<?php

namespace Exceedone\Exment\Tests\Feature;

//use Exceedone\Exment\Database\Seeder\InstallSeeder;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Collection;

class ImportExportTest extends FeatureTestBase
{
    use ImportTrait;
    use TestTrait;
    use DatabaseTransactions;

    /**
     * full path stored export files.
     *
     * @var string
     */
    protected $dirpath;

    protected function init(bool $export, $target_name = null)
    {
        try {
            $this->initAllTest();
            //$this->seed(InstallSeeder::class);
            $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
            if ($export) {
                $this->dirpath = storage_path(path_join_os('app', 'export', 'unittest'));
                if (\File::exists($this->dirpath)) {
                    \File::deleteDirectory($this->dirpath);
                }
                \Exment::makeDirectory($this->dirpath);
            } else {
                $import_path = storage_path(path_join_os('app', 'import', 'unittest'));
                if (\File::exists($import_path)) {
                    \File::deleteDirectory($import_path);
                }
                \Exment::makeDirectory($import_path);
                $source_path = exment_package_path("tests/tmpfile/Feature/$target_name");
                \File::copyDirectory($source_path, $import_path);
                $this->dirpath = 'unittest';
            }
        } catch (\Exception $ex) {
        }
    }

    public function testExportCsv()
    {
        $table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;

        $this->_testExport([
            'table_name' => $table_name,
        ]);
    }

    public function testExportCsvPage()
    {
        $table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;

        $this->_testExport([
            'table_name' => $table_name,
            '--type' => 'page',
            '--page' => 3,
        ]);
    }

    public function testExportXlsx()
    {
        $table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;

        $this->_testExport([
            'table_name' => $table_name,
            '--format' => 'xlsx',
        ]);
    }

    public function testExportXlsxPage()
    {
        $table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;

        $this->_testExport([
            'table_name' => $table_name,
            '--format' => 'xlsx',
            '--type' => 'page',
            '--page' => 5,
            '--count' => 10,
        ]);
    }

    public function testExportCsvView()
    {
        $table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;

        $custom_view = CustomView::where('view_view_name', "$table_name-view-odd")->first();

        $this->_testExport([
            'table_name' => $table_name,
            '--action' => 'view',
            '--view' => $custom_view,
        ]);
    }

    public function testExportXlsxView()
    {
        $table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;

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
        $table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;

        $custom_view = CustomView::where('view_view_name', "$table_name-view-odd")->first();

        $this->_testExport([
            'table_name' => $table_name,
            '--action' => 'view',
            '--view' => $custom_view,
            '--type' => 'page',
            '--page' => 2,
            '--count' => 15,
        ]);
    }

    public function testExportXlsxViewPage()
    {
        $table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;

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

    public function testExportXlsxWithSetting()
    {
        $table_name = 'custom_value_edit_all';

        $custom_view = CustomView::where('view_view_name', "$table_name-view-odd")->first();

        $this->_testExport([
            'table_name' => $table_name,
            '--format' => 'xlsx',
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
        $table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;

        $this->_testChunkExport([
            'table_name' => $table_name,
        ]);
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
        ]);
    }

    public function testImport()
    {
        $this->_testImport('import_test_1');
    }

    public function testImportMulti()
    {
        $this->_testImport('import_test_2');
    }

    public function testImportError()
    {
        $this->_testImport('import_test_3', false);
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

        $this->_compareData($file_path, $params);
    }

    protected function _getFileData(string $file_path, CustomTable $custom_table, array $params)
    {
        $this->assertTrue(\File::exists($file_path));
        $this->assertTrue(\File::size($file_path) > 0);

        if ($params['--format'] == 'csv') {
            $file_array = $this->_getCsvArray($file_path);
        } else {
            $file_array = $this->_getXlsxArray($file_path);
            if (isset($params['--add_setting']) && $params['--add_setting'] == '1') {
                $this->assertTrue(array_key_exists(Model\Define::SETTING_SHEET_NAME, $file_array));
            }
            if (isset($params['--add_relation']) && $params['--add_relation'] == '1') {
                CustomRelation::getRelationsByParent($custom_table)->each(function ($item) use ($file_array) {
                    $this->assertTrue(array_key_exists($item->child_custom_table->table_name, $file_array));
                });
            }
            $file_array = $file_array[$params['table_name']];
        }
        return $file_array;
    }

    protected function _getTableData(CustomTable $custom_table, array $params, int $chunk_no = -1)
    {
        $pager_count = null;
        $custom_view = null;
        if (isset($params['--action']) && $params['--action'] == 'view') {
            $custom_view = CustomView::getEloquent($params['--view']);
            $model = $custom_table->getValueQuery();
            $custom_view->filterSortModel($model);
            $pager_count = $custom_view->pager_count;
        } else {
            $model = $custom_table->getValueQuery()->orderby('id');
        }

        if ($chunk_no > 0) {
            $count = isset($params['--count']) ? $params['--count'] : 1000;
            if ($chunk_no > 1) {
                $model = $model->skip(($chunk_no - 1) * $count);
            }
            $model = $model->take($count);
        }

        if (isset($params['--type']) && $params['--type'] == 'page') {
            $page = isset($params['--page']) ? $params['--page'] : 1;
            $count = isset($params['--count']) ? $params['--count'] : $pager_count;
            $count = empty($count) ? System::grid_pager_count() : $count;
            if ($page > 1) {
                $model = $model->skip(($page - 1) * $count);
            }
            $model = $model->take($count);
        }
        return [$custom_view, $model->get()];
    }

    protected function _compareData(string $file_path, array $params, int $chunk_no = -1)
    {
        $custom_table = CustomTable::getEloquent($params['table_name']);

        list($custom_view, $db_array) = $this->_getTableData($custom_table, $params, $chunk_no);

        if ($chunk_no > 0 && count($db_array) == 0) {
            return false;
        }

        $file_array = $this->_getFileData($file_path, $custom_table, $params);

        $this->assertEquals(count($db_array), count($file_array)-2);

        if (isset($custom_view)) {
            $this->_compareViewData($custom_view, $file_array, $db_array);
        } else {
            $this->_compareAllData($file_array, $db_array, $custom_table);
        }
        return true;
    }

    protected function _compareViewData($custom_view, array $file_array, Collection $db_array)
    {
        foreach ($custom_view->custom_view_columns as $colno => $custom_view_column) {
            foreach ($db_array as $index => $db_data) {
                $db_text = $custom_view_column->column_item->setCustomValue($db_data)
                    ->options(['disable_currency_symbol' => true])->text();
                $file_text = $file_array[$index + 2][$colno];
                $this->assertEquals($db_text, $file_text);
            }
        }
    }

    protected function _compareAllData(array $file_array, Collection $db_array, CustomTable $custom_table)
    {
        $header_array = [];
        foreach ($file_array as $index => $file_data) {
            if ($index == 0) {
                $header_array = $file_data;
            } elseif ($index > 1) {
                $db_data = $db_array[$index - 2];
                foreach ($header_array as $colno => $header) {
                    preg_match('/value\.(.+)/', $header, $matches);
                    if ($matches) {
                        $colvalue = $db_data->getValue($matches[1]);
                    } else {
                        $colvalue = array_get($db_data, $header);
                    }
                    if ($colvalue instanceof CustomValue) {
                        $colvalue = array_get($colvalue, 'id');
                    }
                    if (is_list($colvalue)) {
                        $colvalue = collect($colvalue)->map(function ($item) use ($header, $custom_table) {
                            if ($item instanceof CustomValue) {
                                return array_get($item, 'id');
                            }
                            // if file column, get url
                            elseif (!is_null($file = $this->getFileColumnValue($header, $item, $custom_table))) {
                                return $file;
                            }
                            return $item;
                        })->implode(',');
                    }
                    // if file column, get url
                    elseif (!is_null($file = $this->getFileColumnValue($header, $colvalue, $custom_table))) {
                        return $file;
                    }
                    $this->assertEquals($colvalue, $file_data[$colno]);
                }
            }
        }
    }

    protected function _testChunkExport(array $params)
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

        $start = isset($params['--start']) ? $params['--start'] : 1;
        $end = isset($params['--end']) ? $params['--end'] : 1000;

        for ($i = $start; $i <= $end; $i++) {
            $num = $i;
            if (isset($params['--seqlength'])) {
                $num = sprintf('%0'. $params['--seqlength'] . 'd', $i);
            }
            $file_path = path_join($params['--dirpath'], $params['table_name'] . ".$num." . $params['--format']);
            if (!$this->_compareData($file_path, $params, $i)) {
                break;
            }
        }
    }

    protected function _testImport($target_name, bool $isSuccess = true)
    {
        $this->init(false, $target_name);

        //$maxid = CustomTable::getEloquent($target_name)->getValueModel()->max('id');

        $result = \Artisan::call('exment:import', [
            'dir' => $this->dirpath
        ]);

        $this->assertEquals($result, $isSuccess ? 0 : -1);
    }


    /**
     * Get file value.
     *
     * @param string $header
     * @param mixed $value
     * @return mixed
     */
    protected function getFileColumnValue($header, $value, CustomTable $custom_table)
    {
        if (is_nullorempty($value)) {
            return null;
        }
        $column_name = str_replace('value.', '', $header);
        $custom_column = Model\CustomColumn::getEloquent($column_name, $custom_table);
        if (!ColumnType::isAttachment($custom_column)) {
            return null;
        }
        return Model\File::getUrl($value);
    }
}
