<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\ReplaceFormat\ReplaceFormatService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * #1717: the file of the data selected by a select_table column, as a custom attachment of a
 * notification template.
 *
 * The data is a row of "all_columns_table_fortest". Its column "select_table" selects a row of
 * "custom_value_view_all", whose column "file" holds the file.
 *
 * - ${file:select_table:select_table.file}: the new way, written as ${select_table:column.column}
 * - ${file:select_table:file}: the column names in a row, as before
 */
class ReplaceFormatSelectTableFileTest extends UnitTestBase
{
    use DatabaseTransactions;

    private const FILE_NAME = 'issue1717.txt';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    public function testFileOfSelectedDataIsReplacedBySelectTableFormat(): void
    {
        list($custom_value, $file) = $this->prepareValueAndFile();

        $this->assertSame($file->path, $this->replace('${file:select_table:select_table.file}', $custom_value));
    }

    public function testFileOfSelectedDataIsStillReplacedByColumnNamesInARow(): void
    {
        list($custom_value, $file) = $this->prepareValueAndFile();

        $this->assertSame($file->path, $this->replace('${file:select_table:file}', $custom_value));
    }

    public function testFileOfTheDataItselfIsStillReplaced(): void
    {
        list(, $file, $selected_value) = $this->prepareValueAndFile();

        $this->assertSame($file->path, $this->replace('${file:file}', $selected_value));
    }

    public function testNothingIsReplacedWhenNoDataIsSelected(): void
    {
        list($custom_value) = $this->prepareValueAndFile();
        $custom_value->setValue('select_table', null)->save();

        $this->assertTrue(is_nullorempty($this->replace('${file:select_table:select_table.file}', $custom_value)));
    }

    public function testNothingIsReplacedWhenTheColumnDoesNotExist(): void
    {
        list($custom_value) = $this->prepareValueAndFile();

        $this->assertTrue(is_nullorempty($this->replace('${file:select_table:select_table.not_exists_column}', $custom_value)));
        $this->assertTrue(is_nullorempty($this->replace('${file:select_table:not_exists_column.file}', $custom_value)));
    }

    public function testFileOfSelectedDataIsACustomAttachmentOfTheMailTemplate(): void
    {
        list($custom_value, $file) = $this->prepareValueAndFile();

        $mail_template_table = CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE);
        $this->assertNotNull($mail_template_table);
        $mail_template = $mail_template_table->getValueModel();
        $this->assertNotNull($mail_template);
        $mail_template->setValue('custom_attachments', '${file:select_table:select_table.file}');

        // a method of MailTemplateTrait, which only the model of the mail templates uses
        if (!method_exists($mail_template, 'getCustomAttachments')) {
            $this->fail('The model of the mail templates has no getCustomAttachments().');
        }
        /** @var \Illuminate\Support\Collection<int, ExmentFile|null> $attachments */
        $attachments = $mail_template->getCustomAttachments($custom_value);

        $this->assertCount(1, $attachments);
        $attachment = $attachments->first();
        $this->assertInstanceOf(ExmentFile::class, $attachment);
        $this->assertSame($file->id, $attachment->id);
        $this->assertSame(self::FILE_NAME, $attachment->filename);
    }

    /**
     * Put a file to the column "file" of a row of "custom_value_view_all", and select the row by
     * the column "select_table" of a row of "all_columns_table_fortest".
     *
     * @return array{0: CustomValue, 1: ExmentFile, 2: CustomValue} the data, the file and the selected data
     */
    private function prepareValueAndFile(): array
    {
        $target_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $this->assertNotNull($target_table);
        $target_value = $this->firstValue($target_table);

        $file_column = CustomColumn::getEloquent('file', $target_table);
        $this->assertNotNull($file_column);

        $file = ExmentFile::storeAs(FileType::CUSTOM_VALUE_COLUMN, TestDefine::FILE_TESTSTRING, $target_table->table_name, self::FILE_NAME);
        $target_value->setValue('file', $file->path)->save();
        $file->saveCustomValue($target_value->id, $file_column, $target_table);

        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $this->assertNotNull($custom_table);
        $custom_value = $this->firstValue($custom_table);
        $custom_value->setValue('select_table', $target_value->id)->save();

        return [$custom_value, $file, $target_value];
    }

    /**
     * The first row of the table, as the model CustomTable::getValueModel($id) gives.
     */
    private function firstValue(CustomTable $custom_table): CustomValue
    {
        $id = $custom_table->getValueQuery()->orderBy('id')->value('id');
        $this->assertNotNull($id);

        $custom_value = $custom_table->getValueModel($id);
        $this->assertNotNull($custom_value);

        return $custom_value;
    }

    /**
     * The value the format is replaced by, as MailTemplateTrait::getCustomAttachments() gets it.
     *
     * @return mixed
     */
    private function replace(string $format, CustomValue $custom_value)
    {
        return ReplaceFormatService::replaceTextFromFormat($format, $custom_value, [
            'getReplaceValue' => true,
        ]);
    }
}
