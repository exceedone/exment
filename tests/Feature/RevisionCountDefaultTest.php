<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * ISS00173: EXMENT_REVISION_COUNT (config exment.revision_count_default) is the number of
 * revisions a new table keeps. The table form and the Studio read "exment.revision_count", a
 * key that does not exist, so a new table always got 100 whatever the .env said.
 */
class RevisionCountDefaultTest extends FeatureTestBase
{
    use TestTrait;

    protected const TABLE_NAME = 'iss173_revision_count';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        config(['exment.revision_count_default' => 250]);
    }

    protected function tearDown(): void
    {
        System::clearRequestSession();
        $table = CustomTable::where('table_name', static::TABLE_NAME)->first();
        if (isset($table)) {
            $table->delete();
        }
        parent::tearDown();
    }

    public function testTheTableFormStartsWithTheConfiguredCount(): void
    {
        $html = $this->get(admin_url('table/create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="options\[revision_count\]"[^>]*value="250"|value="250"[^>]*name="options\[revision_count\]"/', strval($html));
    }

    public function testATableMadeInTheStudioKeepsTheConfiguredCount(): void
    {
        System::clearRequestSession();
        $this->post(admin_url('studio'), [
            'table_view_name' => 'ISS00173',
            'table_name' => static::TABLE_NAME,
            'fields' => json_encode([['type' => ColumnType::TEXT, 'label' => 'Name', 'column_name' => 'name']]),
        ])->assertRedirect(admin_urls('data', static::TABLE_NAME, 'create'));

        System::clearRequestSession();
        $table = CustomTable::getEloquent(static::TABLE_NAME);
        $this->assertNotNull($table);
        $this->assertSame(250, intval($table->getOption('revision_count')));
    }
}
