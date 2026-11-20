<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Studio, phase 2 (ISS00231): make a screen from an Excel or CSV file, and take its data in.
 *
 * Making a table creates its data table (DDL), which a test transaction cannot
 * roll back, so each test deletes the tables it made.
 */
class StudioSheetTest extends FeatureTestBase
{
    use TestTrait;

    /**
     * @var array<int, string>
     */
    protected $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $name) {
            System::clearRequestSession();
            $table = CustomTable::where('table_name', $name)->first();
            if (isset($table)) {
                $table->delete();
            }
        }
        parent::tearDown();
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function readSheet(UploadedFile $file)
    {
        System::clearRequestSession();
        return $this->post(admin_url('studio/sheet'), ['sheet' => $file], ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);
    }

    protected function xlsx(): UploadedFile
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([
            ['会社名', '契約日', '社員数', '区分'],
            ['山田商事', null, 12, '見込み'],
            ['佐藤工業', null, 340, '取引中'],
            ['鈴木建設', null, 56, '見込み'],
            ['高橋電機', null, 7, '取引中'],
        ]);
        foreach ([2 => '2026-04-01', 3 => '2025-10-15', 4 => '2026-01-20', 5 => '2024-07-07'] as $row => $date) {
            $sheet->setCellValue('B' . $row, ExcelDate::PHPToExcel(new \DateTime($date)));
            $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('yyyy/mm/dd');
        }
        $path = tempnam(sys_get_temp_dir(), 'studio') . '.xlsx';
        (new Xlsx($book))->save($path);
        return new UploadedFile($path, '顧客一覧.xlsx', null, null, true);
    }

    public function testAnExcelFileGivesTheItemsWithTheirParts(): void
    {
        $response = $this->readSheet($this->xlsx());
        $response->assertOk()->assertJsonPath('result', true)
            ->assertJsonPath('file_name', '顧客一覧.xlsx')
            ->assertJsonPath('row_count', 4)
            ->assertJsonPath('column_count', 4);

        $fields = $response->json('fields');
        $this->assertSame(['会社名', '契約日', '社員数', '区分'], array_column($fields, 'label'));
        $this->assertSame([ColumnType::TEXT, ColumnType::DATE, ColumnType::INTEGER, ColumnType::SELECT], array_column($fields, 'type'));
        $this->assertSame("見込み\n取引中", $fields[3]['choices']);
        $this->assertNotEmpty($response->json('token'));
    }

    public function testTheDataIsTakenInWithTheNewTable(): void
    {
        $this->made[] = 'studio_test_sheet';
        $read = $this->readSheet(UploadedFile::fake()->createWithContent('customers.csv', "会社名,売上,メモ\n山田商事,\"1,200\",よろしく\n佐藤工業,あとで,\n"));
        $read->assertOk();
        $fields = $read->json('fields');
        $this->assertSame([ColumnType::TEXT, ColumnType::TEXT, ColumnType::TEXT], array_column($fields, 'type'));

        // the user makes "売上" a number and drops "メモ": the value that is not a number is left empty
        $placed = [
            ['type' => ColumnType::TEXT, 'label' => '会社名', 'source' => 0],
            ['type' => ColumnType::INTEGER, 'label' => '売上', 'column_name' => 'sales', 'source' => 1],
        ];
        System::clearRequestSession();
        $response = $this->post(admin_url('studio'), [
            'mode' => 'new',
            'table_view_name' => '顧客',
            'table_name' => 'studio_test_sheet',
            'fields' => json_encode($placed),
            'import_data' => '1',
            'sheet_token' => $read->json('token'),
        ]);
        $response->assertRedirect(admin_urls('data', 'studio_test_sheet'));

        System::clearRequestSession();
        $rows = getModelName('studio_test_sheet')::orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('山田商事', $rows[0]->getValue('field_1'));
        $this->assertSame(1200, intval($rows[0]->getValue('sales')));
        $this->assertSame('佐藤工業', $rows[1]->getValue('field_1'));
        $this->assertNull($rows[1]->getValue('sales'));

        // the file read is deleted once taken in
        $this->assertNull(session('studio_sheet'));
    }

    public function testAFileThatIsNotExcelOrCsvIsRefused(): void
    {
        $this->readSheet(UploadedFile::fake()->createWithContent('memo.txt', 'a,b'))
            ->assertStatus(422)
            ->assertJsonPath('result', false)
            ->assertJsonPath('message', exmtrans('studio.error.sheet_file'));
    }

    public function testTakingInWithoutTheReadFileIsAMistake(): void
    {
        System::clearRequestSession();
        $this->from(admin_url('studio/create'))->post(admin_url('studio'), [
            'mode' => 'new',
            'table_view_name' => 'x',
            'table_name' => 'studio_test_no_sheet',
            'fields' => json_encode([['type' => ColumnType::TEXT, 'label' => 'a', 'source' => 0]]),
            'import_data' => '1',
            'sheet_token' => 'not-the-token',
        ])->assertRedirect(admin_url('studio/create'));

        $this->assertArrayHasKey('sheet', session('studio_errors'));
        $this->assertFalse(CustomTable::where('table_name', 'studio_test_no_sheet')->exists());
    }

    public function testAUserWithoutTheTablePermissionCannotReadAFile(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2));
        System::clearRequestSession();
        if (\Exceedone\Exment\Services\Studio\BlankFormBuilder::available()) {
            $this->markTestSkipped('user2 has the table permission in this test data');
        }
        $this->readSheet(UploadedFile::fake()->createWithContent('a.csv', "a\n1\n"))->assertForbidden();
    }
}
