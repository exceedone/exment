<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Controllers\EncryptedValueController;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleGroupType;
use Exceedone\Exment\Grid\Tools\GridInlineEditor;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\DataAccessLog;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Encryption\DataKeys;
use Exceedone\Exment\Services\Encryption\EncryptedColumns;
use Exceedone\Exment\Services\Encryption\EncryptedValue;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\LogManagement\LogWriter;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Validator\EncryptedMaskRule;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * The encrypted columns on the screens, stage 1 part 2 (ISS00148): masked, the "show" button
 * for a user with "Show the encrypted values", every reveal in the data access log, the form
 * that never holds the value.
 */
class EncryptedColumnsRevealTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected const TABLE = TestDefine::TESTDATA_TABLE_NAME_EDIT;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        config(['exment.data_encryption_keys' => 'k1:' . base64_encode(str_repeat('a', 32))]);
        DataKeys::reset();
        Features::save([EncryptedColumns::FEATURE => ['enabled' => true]]);
        $column = $this->column();
        $column->setOption('encrypted', '1');
        $column->setOption('encrypted_mask_visible', '4');
        $column->save();
        System::clearCache();
    }

    protected function tearDown(): void
    {
        EncryptedColumns::$formPost = null;
        config(['exment.data_encryption_keys' => '']);
        DataKeys::reset();
        System::clearCache();
        parent::tearDown();
    }

    protected function table(): CustomTable
    {
        /** @var CustomTable $table */
        $table = CustomTable::getEloquent(static::TABLE);
        return $table;
    }

    protected function column(): CustomColumn
    {
        /** @var CustomColumn $column */
        $column = CustomColumn::getEloquent('text', $this->table());
        return $column;
    }

    protected function record(string $text): CustomValue
    {
        $record = $this->table()->getValueModel();
        $record->setValue('text', $text);
        $record->save();
        /** @var CustomValue $fresh */
        $fresh = $this->table()->getValueModel($record->id);
        return $fresh;
    }

    public function testTheValueIsShownMasked(): void
    {
        $record = $this->record('090-1234-5678');
        $item = $this->column()->column_item->setCustomValue($record);
        $this->assertSame('****5678', $item->text());

        $html = strval($item->html());
        $this->assertStringContainsString('****5678', $html);
        $this->assertStringNotContainsString('090-1234', $html);
        // an administrator may show it
        $this->assertStringContainsString('exm-encrypted-reveal', $html);
        $this->assertStringContainsString('/reveal/text', $html);

        // a revision keeps the encrypted text: masked as well
        $encrypted = EncryptedValue::encrypt('Secret Value 9876');
        $this->assertSame('****9876', EncryptedColumns::masked($this->column(), $encrypted));
        $this->assertSame(strval(exmtrans('encrypted.unreadable')), EncryptedColumns::masked($this->column(), 'enc:v1:k9:xxx'));
    }

    public function testRevealIsLoggedEveryTime(): void
    {
        $record = $this->record('Yamada Taro');
        $controller = new EncryptedValueController();

        $before = DataAccessLog::query()->where('kind', DataAccessLog::KIND_REVEAL)->count();
        foreach ([1, 2] as $n) {
            $response = $controller->reveal(static::TABLE, $record->id, 'text');
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('Yamada Taro', $response->getData(true)['value']);
            $this->assertStringContainsString('no-store', strval($response->headers->get('Cache-Control')));
        }
        LogWriter::flush();
        $rows = DataAccessLog::query()->where('kind', DataAccessLog::KIND_REVEAL)->orderByDesc('id')->get();
        $this->assertSame($before + 2, $rows->count(), 'every reveal, not deduplicated');
        /** @var DataAccessLog $row */
        $row = $rows->first();
        $this->assertSame(intval($record->id), intval($row->record_id));
        $this->assertSame(intval($this->column()->id), intval($row->custom_column_id));
        $this->assertSame(intval($this->table()->id), intval($row->custom_table_id));

        // a column not encrypted: nothing to show
        $this->assertSame(404, $controller->reveal(static::TABLE, $record->id, 'integer')->getStatusCode());
    }

    public function testAUserWithoutThePermissionCannotReveal(): void
    {
        $record = $this->record('Suzuki Hanako');
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
        System::clearRequestSession();
        $this->assertFalse(EncryptedColumns::canReveal($this->table(), $record->id));

        $before = DataAccessLog::query()->where('kind', DataAccessLog::KIND_REVEAL)->count();
        $response = (new EncryptedValueController())->reveal(static::TABLE, $record->id, 'text');
        $this->assertSame(403, $response->getStatusCode());
        $this->assertArrayNotHasKey('value', $response->getData(true));
        LogWriter::flush();
        $this->assertSame($before, DataAccessLog::query()->where('kind', DataAccessLog::KIND_REVEAL)->count());

        $html = strval($this->column()->column_item->setCustomValue($record)->html());
        $this->assertStringNotContainsString('exm-encrypted-reveal', $html, 'no button');
    }

    public function testTheFormKeepsTheValueLeftEmpty(): void
    {
        $record = $this->record('Ito Saburo');
        $raw = \DB::table(getDBTableName($this->table()))->where('id', $record->id)->value('value');

        EncryptedColumns::$formPost = true;
        $record->setValue('text', null);
        $record->save();
        $this->assertSame('Ito Saburo', $this->record2($record->id)->getValue('text'));
        // the json as data: MariaDB keeps the keys in the order written, MySQL sorts them
        $this->assertEquals(json_decode($raw, true), json_decode(\DB::table(getDBTableName($this->table()))->where('id', $record->id)->value('value'), true));

        // a new value replaces it
        $record = $this->record2($record->id);
        $record->setValue('text', 'Ito Shiro');
        $record->save();
        $this->assertSame('Ito Shiro', $this->record2($record->id)->getValue('text'));
    }

    public function testTheFormRefusesAMaskedValue(): void
    {
        $record = $this->record('090-1234-5678');

        // the box of the form has the rule
        $field = $this->column()->column_item->setCustomValue($record)->getAdminField();
        $rules = (new \ReflectionProperty($field, 'rules'))->getValue($field);
        $this->assertNotEmpty(array_filter((array) $rules, function ($rule) {
            return $rule instanceof EncryptedMaskRule;
        }));

        // edit: its own mask is "not changed", another mask is refused, a full value is taken
        $edit = new EncryptedMaskRule($this->column(), $record->id);
        $this->assertTrue($edit->passes('value.text', '****5678'));
        $this->assertFalse($edit->passes('value.text', '****0000'));
        $this->assertFalse($edit->passes('value.text', '****'));
        $this->assertTrue($edit->passes('value.text', '090-9999-0000'));
        $this->assertTrue($edit->passes('value.text', ''));
        $this->assertSame(strval(exmtrans('encrypted.masked_form')), $edit->message());

        // create: no value yet, so a mask is always refused (never saved as empty)
        $create = new EncryptedMaskRule($this->column(), null);
        $this->assertFalse($create->passes('value.text', '****5555'));
        $this->assertTrue($create->passes('value.text', 'new value 5555'));
    }

    protected function record2(int $id): CustomValue
    {
        System::clearRequestSession();
        /** @var CustomValue $record */
        $record = $this->table()->getValueModel($id);
        return $record;
    }

    public function testThePermissionAndTheListEditor(): void
    {
        $this->assertContains(Permission::CUSTOM_VALUE_REVEAL, RoleGroupType::TABLE()->getRoleGroupPermissions());
        $this->assertFalse(GridInlineEditor::isEditable($this->column()), 'no editor in the list');

        Features::save([EncryptedColumns::FEATURE => ['enabled' => false]]);
        $this->assertNotContains(Permission::CUSTOM_VALUE_REVEAL, RoleGroupType::TABLE()->getRoleGroupPermissions());
        $this->assertNotContains(Permission::CUSTOM_VALUE_REVEAL, RoleGroupType::MASTER()->getRoleGroupPermissions());
    }
}
