<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\DataImportExport\Providers\Export\DefaultTableProvider;
use Exceedone\Exment\Services\Encryption\DataKeys;
use Exceedone\Exment\Services\Encryption\EncryptedColumns;
use Exceedone\Exment\Services\Mcp\McpTools;
use Exceedone\Exment\Services\Meili\DocumentMapper;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * The encrypted columns where the values leave Exment, stage 1 part 3 (ISS00148): the API and
 * MCP give them masked (the API has .../reveal/{column}), the export masks them, the full-text
 * search does not get them; an import or a copy saves them encrypted (the save of a row).
 */
class EncryptedColumnsOutsideTest extends UnitTestBase
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
        $column = $this->column();
        $column->setOption('encrypted', '1');
        $column->save();
        System::clearCache();
    }

    protected function tearDown(): void
    {
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

    protected function column(string $name = 'text'): CustomColumn
    {
        /** @var CustomColumn $column */
        $column = CustomColumn::getEloquent($name, $this->table());
        return $column;
    }

    protected function record(): CustomValue
    {
        $record = $this->table()->getValueModel();
        $record->setValue('text', 'customer-0001-9876');
        $record->setValue('integer', 12);
        $record->save();
        System::clearRequestSession();
        /** @var CustomValue $fresh */
        $fresh = $this->table()->getValueModel($record->id);
        return $fresh;
    }

    public function testTheValuesAreMaskedToLeave(): void
    {
        $values = EncryptedColumns::maskValues(['text' => 'customer-0001-9876', 'integer' => 12], $this->table());
        $this->assertSame(['text' => '****9876', 'integer' => 12], $values);
        $this->assertSame('not an array', EncryptedColumns::maskValues('not an array', $this->table()));
    }

    public function testMcpGivesThemMasked(): void
    {
        $record = $this->record();
        /** @var LoginUser $user */
        $user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $row = new \ReflectionMethod(McpTools::class, 'row');
        $row->setAccessible(true);
        $result = $row->invoke(new McpTools($user), $record);
        $this->assertSame('****9876', $result['value']['text']);
        $this->assertSame(12, intval($result['value']['integer']));
        $this->assertStringNotContainsString('customer-0001', json_encode($result) ?: '');
    }

    public function testTheExportMasksThem(): void
    {
        $record = $this->record();
        $provider = new DefaultTableProvider(['custom_table' => $this->table()]);
        $body = new \ReflectionMethod(DefaultTableProvider::class, 'getBodyValue');
        $body->setAccessible(true);
        $this->assertSame('****9876', $body->invoke($provider, $record->getValue('text'), $this->column(), ConditionType::COLUMN, $record));
    }

    public function testTheFullTextSearchDoesNotGetThem(): void
    {
        $record = $this->record();
        $document = (new DocumentMapper())->map($record, [$this->column(), $this->column('integer')], static::TABLE, null);
        $this->assertArrayNotHasKey('text', $document);
        $this->assertStringNotContainsString('customer-0001', json_encode($document) ?: '');
    }

    public function testTheApiMasksThemAndHasReveal(): void
    {
        $trait = (string) file_get_contents(exment_package_path('src/Controllers/ApiDataTrait.php'));
        $this->assertStringContainsString('EncryptedColumns::maskValues($custom_value->value, $this->custom_table)', $trait);
        // masked before "dot" turns the row into an array
        $this->assertLessThan(strpos($trait, "array_dot(\$custom_value->toArray())"), strpos($trait, 'EncryptedColumns::maskValues($custom_value->value'));

        $routes = (string) file_get_contents(exment_package_path('src/Providers/RouteServiceProvider.php'));
        $this->assertStringContainsString('"data/{tableKey}/{id}/reveal/{column}", "ApiDataController@dataReveal"', $routes);
    }

    public function testACopyIsSavedEncrypted(): void
    {
        $record = $this->record();
        $copy = $this->table()->getValueModel();
        $copy->setValue($record->value);
        $copy->save();
        $raw = \DB::table(getDBTableName($this->table()))->where('id', $copy->id)->value('value');
        $this->assertStringNotContainsString('customer-0001', strval($raw));
        System::clearRequestSession();
        /** @var CustomValue $fresh */
        $fresh = $this->table()->getValueModel($copy->id);
        $this->assertSame('customer-0001-9876', $fresh->getValue('text'));
    }
}
