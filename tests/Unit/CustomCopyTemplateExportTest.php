<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * #1689: the template export of a data copy setting (custom_copies) wrote the name of the "from"
 * table into to_custom_table_name as well. Both ids were replaced by one definition, which looks
 * the table up once (with the "from" id) and gives its name to both keys.
 */
class CustomCopyTemplateExportTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        System::clearCache();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    public function testExportWritesTheNameOfEachTable(): void
    {
        $from = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $to = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);

        $items = $this->exportItems($from, $to);

        $this->assertSame($from->table_name, array_get($items, 'from_custom_table_name'));
        $this->assertSame($to->table_name, array_get($items, 'to_custom_table_name'));
        // the ids are replaced by the names
        $this->assertArrayNotHasKey('from_custom_table_id', $items);
        $this->assertArrayNotHasKey('to_custom_table_id', $items);
    }

    public function testExportOfACopyToTheSameTable(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);

        $items = $this->exportItems($table, $table);

        $this->assertSame($table->table_name, array_get($items, 'from_custom_table_name'));
        $this->assertSame($table->table_name, array_get($items, 'to_custom_table_name'));
    }

    /**
     * Save a data copy setting (rolled back) and get what the template export writes for it.
     *
     * @return array<string, mixed>
     */
    private function exportItems(CustomTable $from, CustomTable $to): array
    {
        $custom_copy = new CustomCopy();
        $custom_copy->from_custom_table_id = $from->id;
        $custom_copy->to_custom_table_id = $to->id;
        $custom_copy->save();

        /** @var CustomCopy $saved */
        $saved = CustomCopy::query()->whereKey($custom_copy->id)->firstOrFail();

        return $saved->getTemplateExportItems();
    }
}
