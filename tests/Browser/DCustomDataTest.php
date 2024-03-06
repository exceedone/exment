<?php

namespace Exceedone\Exment\Tests\Browser;

use Illuminate\Support\Facades\Storage;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;

class DCustomDataTest extends ExmentKitTestCase
{
    use ExmentKitPrepareTrait;

    /**
     * pre-excecute process before test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->login();
    }

    /**
     * prepare test table.
     */
    public function testPrepareTestTable()
    {
        $this->createCustomTable('exmenttest_data');
        deleteDirectory(Storage::disk(config('admin.upload.disk')), 'exmenttest_data');
    }

    /**
     * prepare test columns.
     */
    public function testPrepareTestColumn()
    {
        $this->createCustomColumns('exmenttest_data');
    }

    /**
     * prepare test user.
     */
    public function testPrepareUser()
    {
        $row = CustomTable::where('table_name', 'user')->first();
        $table_name = 'exm__' . array_get($row, 'suuid');

        $cnt = \DB::table($table_name)->whereNull('deleted_at')->count();

        if ($cnt < 2) {
            $data = [
                'value[user_code]' => 'test2',
                'value[user_name]' => 'Test User 2',
                'value[email]' => 'test2@test.com',
            ];
            $this->visit(admin_url('data/user/create'))
                    ->submitForm('admin-submit', $data)
                    ->seePageIs('/admin/data/user')
            ;
        } else {
            $this->assertTrue(true);
        }
    }

    /**
     * prepare test organization.
     */
    public function testPrepareOrganization()
    {
        $row = CustomTable::where('table_name', 'organization')->first();
        $table_name = 'exm__' . array_get($row, 'suuid');

        $cnt = \DB::table($table_name)->whereNull('deleted_at')->count();

        if ($cnt == 0) {
            $data = [
                'value[organization_code]' => 'EX1',
                'value[organization_name]' => 'EX_NAME1',
            ];
            $this->visit(admin_url('data/organization/create'))
                    ->submitForm('admin-submit', $data)
                    ->seePageIs('/admin/data/organization')
            ;
            $data = [
                'value[organization_code]' => 'EX2',
                'value[organization_name]' => 'EX_NAME2',
            ];
            $this->visit(admin_url('data/organization/create'))
                    ->submitForm('admin-submit', $data)
                    ->seePageIs('/admin/data/organization')
            ;
        } else {
            $this->assertTrue(true);
        }
    }

    /**
     * create custom data.
     */
    public function testAddRecordSuccess()
    {
        $row = CustomTable::getEloquent('exmenttest_data');
        $table_name = \getDBTableName($row);

        $pre_cnt = \DB::table($table_name)->whereNull('deleted_at')->count();

        // Create custom data
        $filePath = $this->getTextFilePath();
        //$imagePath = $this->getTextImagePath();
        $this->visit(admin_url('data/exmenttest_data/create'))
                ->type(99, 'value[integer]')
                ->type('EXMENT Test Data 1', 'value[onelinetext]')
                ->type('2019-02-27 10:45:03', 'value[dateandtime]')
                ->select(['Option 1'], 'value[selectfromstaticvalue][]')
                ->select(['1'], 'value[selectsavevalueandlabel][]')
                ->type('EXMENT Test' . "\n" . 'Data Multiline Text', 'value[multiplelinetext]')
                ->type(99.99, 'value[decimal]')
                ->type('https://google.com', 'value[url]')
                ->type('admin@admin.com', 'value[email]')
                ->type('2019-02-26', 'value[date]')
                ->type('13:40:21', 'value[time]')
                ->select(['1'], 'value[selectfromtable][]')
                //->attach($imagePath, 'value[image]')
                ->attach($filePath, 'value[file]')
                ->select(['1'], 'value[user][]')
                ->select(['1'], 'value[organization][]')
                ->press('admin-submit')
                ->seePageIs('/admin/data/exmenttest_data')
                ->assertEquals($pre_cnt + 1, \DB::table($table_name)->whereNull('deleted_at')->count())
        ;
        // Get new data row
        $row = \DB::table($table_name)->whereNull('deleted_at')->orderBy('id', 'desc')->first();
        // Check custom data
        $this->visit(admin_url('data/exmenttest_data/'. $row->id . '/edit'))
                ->seeInField('value[integer]', 99)
                ->seeInField('value[onelinetext]', 'EXMENT Test Data 1')
                ->seeInField('value[dateandtime]', '2019-02-27 10:45:03')
                ->seeIsSelected('value[selectfromstaticvalue][]', 'Option 1')
                ->seeIsSelected('value[selectsavevalueandlabel][]', '1')
                ->seeInField('value[multiplelinetext]', 'EXMENT Test Data Multiline Text')
                ->seeInField('value[decimal]', 99.99)
                ->seeInField('value[url]', 'https://google.com')
                ->seeInField('value[email]', 'admin@admin.com')
                ->seeInField('value[date]', '2019-02-26')
                ->seeInField('value[time]', '13:40:21')
                ->seeIsSelected('value[selectfromtable][]', '1')
                //->see('image.png')
                ->see('file.txt')
                ->seeIsSelected('value[user][]', '1')
                ->seeIsSelected('value[organization][]', '1')
        ;
    }

    /**
     * update custom data.
     */
    public function testEditRecord1()
    {
        $row = CustomTable::getEloquent('exmenttest_data');
        $table_name = \getDBTableName($row);

        $row = \DB::table($table_name)->whereNull('deleted_at')->orderBy('id', 'desc')->first();

        // Update custom data(checkbox field)
        $data = [
            'value[select2value]' => 'value1',
            'value[yesno]' => 1,
        ];
        $this->visit(admin_url('data/exmenttest_data/'. $row->id . '/edit'))
                ->submitForm('admin-submit', $data)
                ->seePageIs('/admin/data/exmenttest_data')
        ;
        // Check custom data
        $this->visit(admin_url('data/exmenttest_data/'. $row->id . '/edit'))
                ->seeInField('value[select2value]', 'value1')
                ->seeInField('value[yesno]', 1)
        ;
    }

    /**
     * update custom data.
     */
    public function testEditRecord2()
    {
        $row = CustomTable::getEloquent('exmenttest_data');
        $table_name = \getDBTableName($row);

        $row = \DB::table($table_name)->whereNull('deleted_at')->orderBy('id', 'desc')->first();

        // Update custom data
        $this->visit(admin_url('data/exmenttest_data/'. $row->id . '/edit'))
                ->type(100, 'value[integer]')
                ->type('EXMENT Test Data 1 Edited', 'value[onelinetext]')
                ->type('EXMENT Test Data Multiline Text', 'value[multiplelinetext]')
                ->type('2018-09-26 19:25:38', 'value[dateandtime]')
                ->type(10.11, 'value[decimal]')
                ->type('2018-09-27', 'value[date]')
                ->type('09:18:54', 'value[time]')
                ->type('edit@admin.com', 'value[email]')
                ->type('https://exment.net', 'value[url]')
                ->select(['Option 2'], 'value[selectfromstaticvalue][]')
                ->select(['2'], 'value[selectsavevalueandlabel][]')
                ->select(['2'], 'value[user][]')
                ->select(['2'], 'value[organization][]')
                ->select(['2'], 'value[selectfromtable][]')
                ->press('admin-submit')
                ->seePageIs('/admin/data/exmenttest_data')
        ;

        // Check custom data
        $this->visit(admin_url('data/exmenttest_data/'. $row->id . '/edit'))
                ->seeInField('value[integer]', 100)
                ->seeInField('value[decimal]', 10.11)
                ->seeInField('value[onelinetext]', 'EXMENT Test Data 1 Edited')
                ->seeInField('value[multiplelinetext]', 'EXMENT Test Data Multiline Text')
                ->seeInField('value[dateandtime]', '2018-09-26 19:25:38')
                ->seeIsSelected('value[selectfromstaticvalue][]', 'Option 2')
                ->seeIsSelected('value[selectsavevalueandlabel][]', '2')
                ->seeInField('value[date]', '2018-09-27')
                ->seeInField('value[time]', '09:18:54')
                ->seeInField('value[email]', 'edit@admin.com')
                ->seeInField('value[url]', 'https://exment.net')
                ->seeIsSelected('value[user][]', '2')
                ->seeIsSelected('value[organization][]', '2')
        ;
    }

    /**
     * create custom relation ont to many.
     */
    public function testAddRelationOneToManyWithUserTable()
    {
        $this->createCustomRelation('exmenttest_data', 'user');
    }

    /**
     * create custom relation many to many.
     */
    public function testAddRelationManyToManyWithOrganizationTable()
    {
        $this->createCustomRelation('exmenttest_data', 'organization', 2);
    }

    /**
     * Check filtered custom data grid display.
     */
    public function testDisplayGridFilter()
    {
        $colname1 = CustomColumn::getEloquent('select_multiple', 'unicode_data_table')->getIndexColumnName();
        $colname2 = CustomColumn::getEloquent('select_valtext_multiple', 'unicode_data_table')->getIndexColumnName();

        // Check custom view data
        $this->visit(admin_url("data/unicode_data_table?$colname1=日本&$colname2=い&$colname2=ち"))
            ->seeInElement('h1', 'unicode_data_table')
            ->seeInElement('th', 'select_multiple')
            ->seeInElement('th', 'select_valtext_multiple')
            ->seeInElement('td.column-select_multiple', '日本')
            ->seeInElement('td.column-select_valtext_multiple', '北海道')
            ->seeInElement('td.column-select_valtext_multiple', '四国')
        ;
    }

    /**
     * Check filtered custom data grid display(encode params).
     */
    public function testDisplayGridFilterEncode()
    {
        $colname1 = CustomColumn::getEloquent('select_multiple', 'unicode_data_table')->getIndexColumnName();
        $colname2 = CustomColumn::getEloquent('select_valtext_multiple', 'unicode_data_table')->getIndexColumnName();
        $filter = urlencode("$colname1=日本&$colname2=い&$colname2=ち");
        // Check custom view data
        $this->visit(admin_url("data/unicode_data_table?$filter"))
            ->seeInElement('h1', 'unicode_data_table')
            ->seeInElement('th', 'select_multiple')
            ->seeInElement('th', 'select_valtext_multiple')
            ->seeInElement('td.column-select_multiple', '日本')
            ->seeInElement('td.column-select_valtext_multiple', '北海道')
            ->seeInElement('td.column-select_valtext_multiple', '四国')
        ;
    }

    // todo 一覧ソートバグ対応用の追加です
    /**
     * Check sorted custom data grid display.
     */
    public function testDisplayGridSort()
    {
        $table_name = \getDBTableName('custom_value_view_all');
        $colname1 = CustomColumn::getEloquent('index_text', 'custom_value_view_all')->getIndexColumnName();
        $sort_str = "_sort%5Bcolumn%5D={$table_name}.{$colname1}&_sort%5Btype%5D=-1&_sort%5Bdirect%5D=1";
        $row = \DB::table($table_name)->whereNull('deleted_at')->orderBy('value->index_text', 'desc')->first();
        $row = json_decode($row->value);

        // Check custom view data
        $this->visit(admin_url("data/custom_value_view_all?$sort_str"))
            ->seeInElement('td.column-index_text', $row->index_text)
        ;

        $sort_str = "_sort%5Bcolumn%5D={$table_name}.id&_sort%5Btype%5D=1&_sort%5Bdirect%5D=1";
        // Check custom view data
        $this->visit(admin_url("data/custom_value_view_all?$sort_str"))
            ->seeInElement('td.column-id', '1')
        ;
    }
}
