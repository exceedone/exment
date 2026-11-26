<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Encryption\ColumnConverter;
use Exceedone\Exment\Services\Encryption\DataKeys;
use Exceedone\Exment\Services\Encryption\EncryptedColumns;
use Exceedone\Exment\Services\Encryption\EncryptedValue;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Validator\EncryptedColumnRule;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * The encrypted columns, stage 1 (ISS00148): the keys, the values, saving and reading a row,
 * and converting the values already there.
 */
class EncryptedColumnsTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected const TABLE = TestDefine::TESTDATA_TABLE_NAME_EDIT;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->keys('k1:' . base64_encode(str_repeat('a', 32)));
    }

    protected function tearDown(): void
    {
        $this->keys('');
        System::clearCache();
        parent::tearDown();
    }

    protected function keys(string $keys): void
    {
        config(['exment.data_encryption_keys' => $keys]);
        DataKeys::reset();
    }

    protected function table(): CustomTable
    {
        /** @var CustomTable $table */
        $table = CustomTable::getEloquent(static::TABLE);
        return $table;
    }

    protected function column(bool $encrypted = true): CustomColumn
    {
        /** @var CustomColumn $column */
        $column = CustomColumn::getEloquent('text', $this->table());
        $column->setOption('encrypted', $encrypted ? '1' : '0');
        $column->save();
        System::clearCache();
        /** @var CustomColumn $column */
        $column = CustomColumn::getEloquent('text', $this->table());
        return $column;
    }

    protected function raw(int $id): ?string
    {
        $json = \DB::table(getDBTableName($this->table()))->where('id', $id)->value('value');
        return array_get(json_decode(strval($json), true) ?: [], 'text');
    }

    protected function record(string $text): CustomValue
    {
        $record = $this->table()->getValueModel();
        $record->setValue('text', $text);
        $record->save();
        return $record;
    }

    public function testTheKeys(): void
    {
        $this->keys('k2:' . base64_encode(str_repeat('b', 32)) . ', bad, k3:short, K4:' . base64_encode(str_repeat('c', 32)) . ',k1:' . base64_encode(str_repeat('a', 32)));
        // the first one encrypts; one not of 32 bytes or with a bad id is not a key
        $this->assertSame(['k2', 'k1'], array_keys(DataKeys::all()));
        $this->assertSame('k2', DataKeys::currentId());

        $new = DataKeys::generate();
        $this->assertSame('k3', $new['id']);
        $this->assertSame(32, strlen((string) base64_decode(substr($new['pair'], 3), true)));

        $this->keys('');
        $this->assertFalse(DataKeys::hasKey());
        $this->expectException(\RuntimeException::class);
        EncryptedValue::encrypt('x');
    }

    public function testAValue(): void
    {
        $encrypted = EncryptedValue::encrypt('090-1234-5678');
        $this->assertStringStartsWith('enc:v1:k1:', $encrypted);
        $this->assertNotSame($encrypted, EncryptedValue::encrypt('090-1234-5678'), 'a new IV each time');
        $this->assertSame('090-1234-5678', EncryptedValue::decrypt($encrypted));
        $this->assertSame('k1', EncryptedValue::keyId($encrypted));
        $this->assertNull(EncryptedValue::keyId('plain'));

        // changed, or of a key that is not here: not read
        $this->assertNull(EncryptedValue::decrypt($encrypted . 'x'));
        $this->assertNull(EncryptedValue::decrypt(str_replace('enc:v1:k1:', 'enc:v1:k9:', $encrypted)));

        $this->assertSame('****5678', EncryptedValue::mask('090-1234-5678'));
        $this->assertSame('****78', EncryptedValue::mask('090-1234-5678', 2));
        $this->assertSame('****', EncryptedValue::mask('090-1234-5678', 0));
        $this->assertSame('****', EncryptedValue::mask('12345678'), 'not longer than twice the shown: hidden whole');
        $this->assertSame('****えお', EncryptedValue::mask('あいうえお', 2));
        $this->assertSame('', EncryptedValue::mask(''));
    }

    public function testTheFeatureIsOffByDefault(): void
    {
        $this->assertArrayHasKey(EncryptedColumns::FEATURE, Features::core());
        $this->assertFalse(Features::enabled(EncryptedColumns::FEATURE));
    }

    public function testARowIsSavedEncryptedAndReadPlain(): void
    {
        $this->column();
        $record = $this->record('Yamada Taro');

        $raw = (string) $this->raw($record->id);
        $this->assertStringStartsWith(EncryptedValue::PREFIX, $raw, 'the database has the encrypted value');
        $this->assertStringNotContainsString('Yamada', (string) \DB::table(getDBTableName($this->table()))->where('id', $record->id)->value('value'));

        /** @var CustomValue $fresh */
        $fresh = $this->table()->getValueModel($record->id);
        $this->assertSame('Yamada Taro', $fresh->getValue('text'));
        $this->assertSame('Yamada Taro', array_get($fresh->value, 'text'));

        // saved again unchanged: the same encrypted text, no revision of it
        $revisions = \DB::table('revisions')->where('revisionable_id', $record->id)->where('revisionable_type', ColumnConverter::morphClass($this->table()))->count();
        $fresh->setValue('text', 'Yamada Taro');
        $fresh->save();
        $this->assertSame($raw, $this->raw($record->id));
        $this->assertSame($revisions, \DB::table('revisions')->where('revisionable_id', $record->id)->where('revisionable_type', ColumnConverter::morphClass($this->table()))->count());

        // changed: encrypted again, and the revision keeps encrypted values only
        $fresh->setValue('text', 'Suzuki Hanako');
        $fresh->save();
        $this->assertStringStartsWith(EncryptedValue::PREFIX, (string) $this->raw($record->id));
        $this->assertNotSame($raw, $this->raw($record->id));
        $revision = \DB::table('revisions')->where('revisionable_id', $record->id)->where('revisionable_type', ColumnConverter::morphClass($this->table()))->orderByDesc('id')->first();
        if (isset($revision)) {
            $this->assertStringNotContainsString('Suzuki', strval($revision->new_value));
            $this->assertStringNotContainsString('Yamada', strval($revision->old_value));
        }
    }

    public function testTheValuesThereAreConvertedAndBack(): void
    {
        $record = $this->record('Sato Jiro');
        $this->assertSame('Sato Jiro', $this->raw($record->id), 'not encrypted yet');

        $column = $this->column();
        $result = ColumnConverter::all($this->table(), $column->column_name, true, 2);
        $this->assertGreaterThanOrEqual(1, $result['converted']);
        $this->assertStringStartsWith(EncryptedValue::PREFIX, (string) $this->raw($record->id));
        // once more: nothing to do
        $this->assertSame(0, ColumnConverter::all($this->table(), $column->column_name, true)['converted']);

        $column = $this->column(false);
        ColumnConverter::all($this->table(), $column->column_name, false);
        $this->assertSame('Sato Jiro', $this->raw($record->id), 'decrypted again');
    }

    public function testARotationAndAMissingKey(): void
    {
        $this->column();
        $record = $this->record('Ito Saburo');
        $this->assertSame('k1', EncryptedValue::keyId($this->raw($record->id)));

        // a new key first: the old values are encrypted again with it
        $this->keys('k2:' . base64_encode(str_repeat('b', 32)) . ',k1:' . base64_encode(str_repeat('a', 32)));
        ColumnConverter::all($this->table(), 'text', true);
        $this->assertSame('k2', EncryptedValue::keyId($this->raw($record->id)));

        // its key gone: left as it is, and read as the encrypted text (saving keeps it)
        $this->keys('k3:' . base64_encode(str_repeat('c', 32)));
        $raw = $this->raw($record->id);
        $result = ColumnConverter::all($this->table(), 'text', false);
        $this->assertGreaterThanOrEqual(1, $result['unreadable']);
        $this->assertSame($raw, $this->raw($record->id));
        System::clearCache();
        /** @var CustomValue $fresh */
        $fresh = $this->table()->getValueModel($record->id);
        $this->assertSame($raw, $fresh->getValue('text'));
    }

    public function testTheOptionNeedsAKeyAndNoIndex(): void
    {
        request()->merge(['column_type' => 'text', 'options' => ['index_enabled' => '0']]);
        $rule = new EncryptedColumnRule();
        $this->assertTrue($rule->passes('options.encrypted', '1'));
        $this->assertTrue($rule->passes('options.encrypted', '0'));

        request()->merge(['options' => ['index_enabled' => '1']]);
        $this->assertFalse($rule->passes('options.encrypted', '1'));

        request()->merge(['options' => ['index_enabled' => '0']]);
        $this->keys('');
        $this->assertFalse($rule->passes('options.encrypted', '1'));

        // a type that is not encrypted: not checked (not kept)
        request()->merge(['column_type' => 'integer']);
        $this->assertTrue($rule->passes('options.encrypted', '1'));
    }

    public function testABackupDoesNotKeepTheKeys(): void
    {
        $backup = (string) file_get_contents(exment_package_path('src/Services/BackupRestore/Backup.php'));
        // the env a backup keeps: EXMENT_* and these
        $this->assertStringContainsString("'keys' => ['APP_KEY', 'APP_LOCALE', 'APP_TIMEZONE'],", $backup);
        $this->assertStringNotContainsString("'DATA_ENCRYPTION_KEYS'", $backup);
        $this->assertStringStartsNotWith('EXMENT_', 'DATA_ENCRYPTION_KEYS');
        $this->assertStringContainsString("env('DATA_ENCRYPTION_KEYS'", (string) file_get_contents(exment_package_path('config/exment.php')));
    }
}
