<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;

/**
 * #1733: the "child table" filter of the relation setting list (admin/relation/{tableKey}) was
 * registered with the relation name "child_custom_table", which is not a column of
 * custom_relations, so searching with it ended in "Unknown column 'child_custom_table'".
 */
class CustomRelationGridFilterTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    public function testChildTableFilterSearchesByChildCustomTableId(): void
    {
        $parent = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $child = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
        $relation = CustomRelation::query()
            ->where('parent_custom_table_id', $parent->id)
            ->where('child_custom_table_id', $child->id)
            ->first();
        $this->assertNotNull($relation, 'seed data has a relation from parent_table to child_table');

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            // the list query: custom_relations of this parent table, narrowed by the child table
            if (strpos($query->sql, 'custom_relations') !== false
                && strpos($query->sql, 'parent_custom_table_id') !== false
                && strpos($query->sql, 'child_custom_table_id') !== false) {
                $queries[] = $query;
            }
        });

        $response = $this->get(
            admin_url("relation/{$parent->table_name}") . '?' . http_build_query(['child_custom_table_id' => $child->id]),
            ['X-Requested-With' => 'XMLHttpRequest']
        );

        $e = $response->exceptions->first();
        $this->assertSame(200, $response->getStatusCode(), $e ? get_class($e) . ': ' . $e->getMessage() : '');
        // the grid catches a query error and prints it in the page with status 200
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->getContent());

        // the filter value reaches the query as a condition on the real column
        $this->assertNotEmpty($queries, 'the list query has a condition on custom_relations.child_custom_table_id');
        $matched = collect($queries)->contains(function ($query) use ($parent, $child) {
            $bindings = array_map('strval', $query->bindings);
            return in_array(strval($parent->id), $bindings, true) && in_array(strval($child->id), $bindings, true);
        });
        $this->assertTrue($matched, 'the parent table id and the selected child table id are bound to the list query');
    }

    public function testRelationTypeFilterStillWorksTogether(): void
    {
        $parent = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $child = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);

        $response = $this->get(
            admin_url("relation/{$parent->table_name}") . '?' . http_build_query([
                'child_custom_table_id' => $child->id,
                'relation_type' => RelationType::ONE_TO_MANY,
            ]),
            ['X-Requested-With' => 'XMLHttpRequest']
        );

        $e = $response->exceptions->first();
        $this->assertSame(200, $response->getStatusCode(), $e ? get_class($e) . ': ' . $e->getMessage() : '');
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
    }
}
