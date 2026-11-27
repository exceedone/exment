<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * The data import (POST admin/data/{tableKey}/import and exment:import) with a file it cannot read.
 *
 * Before the fix a ".xlsx" that is not a real xlsx (a broken or renamed file) made the spout reader
 * throw, the request answered a blank 500 and the import modal showed nothing. It now answers the
 * "format is incorrect" message in the modal, like the other import errors, and imports nothing.
 * The files that were read before (a valid xlsx, a csv, a header the table does not know) are
 * imported as before.
 */
class ImportBrokenFileTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const TABLE = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;

    protected ?string $importDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        // saving a record notifies the table's notify targets
        Notification::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->importDir) && \File::exists($this->importDir)) {
            \File::deleteDirectory($this->importDir);
        }
        System::clearRequestSession();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function brokenXlsxProvider(): array
    {
        return [
            // the file of the r1 test: a zip signature and nothing else
            'zip signature only' => ["PK\x03\x04 broken xlsx content"],
            'plain text renamed' => ['this is not a spreadsheet'],
            'empty file' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenXlsxProvider')]
    public function testABrokenXlsxAnswersTheFormatError(string $content): void
    {
        $count = $this->valueCount();

        $this->assertFormatError($this->postImport(UploadedFile::fake()->createWithContent(static::TABLE . '.xlsx', $content)));
        $this->assertSame($count, $this->valueCount());
    }

    public function testAZipThatIsNotAnXlsxAnswersTheFormatError(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'exm');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('readme.txt', 'not a workbook');
        $zip->close();
        $content = (string) file_get_contents($path);
        @unlink($path);

        $count = $this->valueCount();
        $this->assertFormatError($this->postImport(UploadedFile::fake()->createWithContent(static::TABLE . '.xlsx', $content)));
        $this->assertSame($count, $this->valueCount());
    }

    public function testAnEmptyCsvKeepsItsAnswer(): void
    {
        // the csv reader reads an empty file as no rows: nothing is imported, as before the fix
        $count = $this->valueCount();
        $this->postImport(UploadedFile::fake()->createWithContent(static::TABLE . '.csv', ''))
            ->assertOk()->assertJson(['result' => true]);
        $this->assertSame($count, $this->valueCount());
    }

    public function testACsvOfBinaryDataImportsNothingWithoutAServerError(): void
    {
        $count = $this->valueCount();
        $response = $this->postImport(UploadedFile::fake()->createWithContent(static::TABLE . '.csv', "PK\x03\x04\x00\xff\xfe binary"));
        $this->assertLessThan(500, $response->getStatusCode());
        $this->assertSame($count, $this->valueCount());
    }

    public function testABrokenZipOfCsvAnswersTheFormatErrorAsBefore(): void
    {
        $count = $this->valueCount();
        $this->assertFormatError($this->postImport(UploadedFile::fake()->createWithContent(static::TABLE . '.zip', "PK\x03\x04 broken zip content")));
        $this->assertSame($count, $this->valueCount());
    }

    public function testAValidXlsxIsImportedAsBefore(): void
    {
        $file = $this->makeXlsx([
            ['id', 'value.text', 'value.index_text'],
            ['ID', 'text', 'index_text'],
            ['', 'broken test one', 'broken test key'],
            ['', 'broken test two', 'broken test key'],
        ]);

        $this->postImport($file)->assertOk()->assertJson(['result' => true, 'toastr' => exmtrans('common.message.import_success')]);
        $this->assertSame(['broken test one', 'broken test two'], $this->importedTexts());
    }

    public function testAnUnknownHeaderIsSkippedAsBefore(): void
    {
        $file = $this->makeXlsx([
            ['id', 'value.text', 'value.index_text', 'value.not_exist_col', 'bad header'],
            ['ID', 'text', 'index_text', 'not exist', 'bad'],
            ['', 'broken test one', 'broken test key', 'ignored', 'ignored'],
        ]);

        $this->postImport($file)->assertOk()->assertJson(['result' => true]);
        $this->assertSame(['broken test one'], $this->importedTexts());

        $value = CustomTable::getEloquent(static::TABLE)->getValueModel()->query()
            ->where('value->index_text', 'broken test key')->first();
        $this->assertNull($value->getValue('not_exist_col'));
        $this->assertArrayNotHasKey('not_exist_col', (array) $value->value);
    }

    public function testTheImportCommandReportsABrokenXlsx(): void
    {
        $dir = 'unittest_broken_' . short_uuid();
        $this->importDir = storage_path(path_join_os('app', 'import', $dir));
        \Exment::makeDirectory($this->importDir);
        \File::put(path_join_os($this->importDir, static::TABLE . '.xlsx'), "PK\x03\x04 broken xlsx content");

        $count = $this->valueCount();
        $result = \Artisan::call('exment:import', ['dir' => $dir]);
        $output = \Artisan::output();

        // the command still stops with an error, now with the readable message
        $this->assertSame(-1, $result);
        $this->assertStringContainsString(exmtrans('error.failure_import_file'), $output);
        $this->assertStringNotContainsString('for reading', $output);
        $this->assertSame($count, $this->valueCount());
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postImport(UploadedFile $file): TestResponse
    {
        System::clearRequestSession();

        return $this->post(admin_urls('data', static::TABLE, 'import'), [
            'custom_table_file' => $file,
            'select_primary_key' => 'id',
        ]);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    protected function assertFormatError(TestResponse $response): void
    {
        // getAjaxResponse answers a failed result with 400, which the import modal reads
        $response->assertStatus(400)->assertJson([
            'result' => false,
            'toastr' => exmtrans('common.message.import_error'),
            'errors' => ['import_error_message' => ['type' => 'input', 'message' => exmtrans('error.failure_import_file')]],
        ]);
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    protected function makeXlsx(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(static::TABLE);
        $sheet->fromArray($rows, null, 'A1', true);

        $path = path_join_os(sys_get_temp_dir(), 'exm_' . short_uuid() . '.xlsx');
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $content = (string) file_get_contents($path);
        @unlink($path);

        return UploadedFile::fake()->createWithContent(static::TABLE . '.xlsx', $content);
    }

    protected function valueCount(): int
    {
        return CustomTable::getEloquent(static::TABLE)->getValueModel()->query()->withTrashed()->count();
    }

    /**
     * @return array<int, string>
     */
    protected function importedTexts(): array
    {
        return CustomTable::getEloquent(static::TABLE)->getValueModel()->query()
            ->where('value->index_text', 'broken test key')
            ->get()
            ->map(fn ($custom_value) => $custom_value->getValue('text'))
            ->sort()
            ->values()
            ->all();
    }
}
