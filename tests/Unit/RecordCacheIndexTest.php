<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\AuthUserOrgHelper;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/**
 * The cached records are found by an index (UseRequestSessionTrait::recordsCacheBy) and
 * the organization tree is made with indexes (AuthUserOrgHelper). These must return the same
 * as checking every record. (#1346)
 */
class RecordCacheIndexTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        System::clearCache();
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    public function testCustomTableGetEloquent(): void
    {
        $tables = CustomTable::query()->get();
        $this->assertGreaterThan(0, $tables->count());

        foreach ($tables as $table) {
            $this->assertSame($table->id, CustomTable::getEloquent($table->id)->id);
            $this->assertSame($table->id, CustomTable::getEloquent((string)$table->id)->id);
            $this->assertSame($table->id, CustomTable::getEloquent($table->table_name)->id);
            $this->assertSame($table->id, CustomTable::getEloquent(['table_name' => $table->table_name])->id);
            $this->assertSame(getModelName($table), getModelName($table->table_name));
            $this->assertSame(getModelName($table), getModelName($table->id));
        }

        $this->assertNull(CustomTable::getEloquent('not_exists_table_1346'));
        $this->assertNull(CustomTable::getEloquent(99999999));
        $this->assertNull(getModelName('not_exists_table_1346'));
    }

    public function testCustomColumnGetEloquent(): void
    {
        $columns = CustomColumn::query()->get();
        $this->assertGreaterThan(0, $columns->count());

        foreach ($columns as $column) {
            $this->assertSame($column->id, CustomColumn::getEloquent($column->id)->id);
            $this->assertSame($column->id, CustomColumn::getEloquent((string)$column->id)->id);
            $this->assertSame($column->id, CustomColumn::getEloquent($column->column_name, $column->custom_table_id)->id);

            $table = CustomTable::getEloquent($column->custom_table_id);
            $this->assertSame($column->id, CustomColumn::getEloquent($column->column_name, $table)->id);
            $this->assertSame($column->id, CustomColumn::getEloquent($column->column_name, $table->table_name)->id);
        }

        $table = CustomTable::query()->first();
        $this->assertNull(CustomColumn::getEloquent('not_exists_column_1346', $table));
        $this->assertNull(CustomColumn::getEloquent(99999999));
    }

    public function testHasManyCache(): void
    {
        foreach (CustomView::query()->get() as $view) {
            $expected = CustomViewColumn::allRecordsCache(function ($record) use ($view) {
                return $record->custom_view_id == $view->id;
            }, false);

            $actual = $view->hasManyCache(CustomViewColumn::class, 'custom_view_id');
            // same records, same keys, same order
            $this->assertSame($expected->keys()->all(), $actual->keys()->all());
            $this->assertSame($expected->pluck('id')->all(), $actual->pluck('id')->all());
        }

        // custom_columns of each table
        foreach (CustomTable::query()->get() as $table) {
            $expected = CustomColumn::query()->where('custom_table_id', $table->id)->orderBy('order')->orderBy('id')->pluck('id')->sort()->values()->all();
            $actual = $table->custom_columns_cache->pluck('id')->sort()->values()->all();
            $this->assertSame($expected, $actual);
        }
    }

    public function testIndexIsMadeAgainAfterClearCache(): void
    {
        $table = CustomTable::query()->first();
        $this->assertSame($table->table_view_name, CustomTable::getEloquent($table->table_name)->table_view_name);

        DB::table('custom_tables')->where('id', $table->id)->update(['table_view_name' => 'changed_1346']);
        System::clearCache();

        $this->assertSame('changed_1346', CustomTable::getEloquent($table->table_name)->table_view_name);
        $this->assertSame('changed_1346', CustomTable::getEloquent($table->id)->table_view_name);
    }

    public function testNotFoundReadsTheRecordsAgain(): void
    {
        // make the cache
        $this->assertNotNull(CustomTable::getEloquent(CustomTable::query()->first()->id));

        // a record added without clearing the cache (e.g. made in this request) is found, as before
        $id = DB::table('custom_tables')->insertGetId([
            'suuid' => short_uuid(),
            'table_name' => 'added_1346',
            'table_view_name' => 'added_1346',
            'options' => '{}',
        ]);

        $this->assertSame($id, CustomTable::getEloquent('added_1346')->id);
        $this->assertSame($id, CustomTable::getEloquent($id)->id);
    }

    public function testOrganizationIdsForQuery(): void
    {
        $this->assertOrganizationIds();
    }

    public function testOrganizationIdsForQueryWithDeletedUserAndOrganization(): void
    {
        $orgModel = getModelName(SystemTableName::ORGANIZATION);
        $userModel = getModelName(SystemTableName::USER);
        $index = $orgModel::getParentOrgIndexName();
        $pivot = CustomRelation::getRelationNameByTables(SystemTableName::ORGANIZATION, SystemTableName::USER);

        // a deleted user that belongs to organizations
        $userModel::withoutGlobalScopes()->whereIn('id', DB::table($pivot)->pluck('child_id'))->orderBy('id', 'desc')->first()->delete();
        // a deleted organization that has children
        $parent = $orgModel::withoutGlobalScopes()->whereIn('id', $orgModel::withoutGlobalScopes()->whereNotNull($index)->pluck($index))->first();
        if (isset($parent)) {
            $parent->delete();
        }
        System::clearCache();

        $this->assertOrganizationIds();
    }

    /**
     * Compare AuthUserOrgHelper::getOrganizationIdsForQuery() with a simple implementation, for all users and filter types.
     */
    protected function assertOrganizationIds(): void
    {
        $orgModel = getModelName(SystemTableName::ORGANIZATION);
        $userModel = getModelName(SystemTableName::USER);
        $index = $orgModel::getParentOrgIndexName();
        $pivot = CustomRelation::getRelationNameByTables(SystemTableName::ORGANIZATION, SystemTableName::USER);

        // deleted organizations and users are not contained (the same as the "users" relation)
        $parents = [];
        foreach ($orgModel::withoutGlobalScope(CustomValueModelScope::class)->get(['id', $index]) as $org) {
            $parents[$org->id] = $org->{$index};
        }
        $activeUserIds = $userModel::withoutGlobalScope(CustomValueModelScope::class)->pluck('id')->all();
        $joined = DB::table($pivot)->whereIn('child_id', $activeUserIds)->whereIn('parent_id', array_keys($parents))->get();
        // check the deleted users too
        $userIds = $userModel::withoutGlobalScopes()->pluck('id')->all();

        $ancestors = function ($id) use ($parents) {
            $result = [];
            $target = $id;
            while (isset($parents[$target]) && $parents[$target] != $id && array_key_exists($parents[$target], $parents) && !in_array($parents[$target], $result)) {
                $target = $parents[$target];
                $result[] = (int)$target;
            }
            return $result;
        };
        $descendants = function ($id) use ($parents, &$descendants) {
            $result = [];
            foreach ($parents as $child => $parent) {
                if ($parent == $id && $child != $id) {
                    $result[] = (int)$child;
                    $result = array_merge($result, $descendants($child));
                }
            }
            return $result;
        };

        $checked = 0;
        foreach ($userIds as $userId) {
            foreach ([JoinedOrgFilterType::ALL, JoinedOrgFilterType::ONLY_UPPER, JoinedOrgFilterType::ONLY_DOWNER, JoinedOrgFilterType::ONLY_JOIN] as $type) {
                $expected = [];
                foreach ($joined->where('child_id', $userId) as $row) {
                    $expected[] = (int)$row->parent_id;
                    if (JoinedOrgFilterType::isGetDowner($type)) {
                        $expected = array_merge($expected, $ancestors($row->parent_id));
                    }
                    if (JoinedOrgFilterType::isGetUpper($type)) {
                        $expected = array_merge($expected, $descendants($row->parent_id));
                    }
                }

                System::clearRequestSession();
                $actual = AuthUserOrgHelper::getOrganizationIdsForQuery($type, $userId);

                sort($expected);
                sort($actual);
                $this->assertSame($expected, $actual, "user $userId, type $type");
                $checked++;
            }
        }
        $this->assertGreaterThan(0, $checked);
    }
}
