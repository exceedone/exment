<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Controllers\SearchController;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Form\Field\RelationSearchList;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Search\RelationSearchSetting;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Which tables the related search shows and in which order (#1570).
 *
 * The fixtures are made here (one parent table with two 1:n children and one
 * n:n child; metadata only, rolled back with the transaction), so the test
 * does not depend on the seeded tables.
 */
class RelationSearchSettingTest extends UnitTestBase
{
    use DatabaseTransactions;
    use CustomTableTrait;

    protected const LANGS = ['de', 'en', 'es', 'ja', 'ko', 'vi', 'zh_CN'];

    /**
     * @var CustomTable
     */
    protected $parent;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $admin = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertNotNull($admin);
        $this->be($admin);

        $suffix = strtolower(substr(md5(uniqid('', true)), 0, 8));
        $this->parent = $this->_createSimpleTable('rs_parent_' . $suffix);
        foreach ([
            ['rs_child_a_', RelationType::ONE_TO_MANY],
            ['rs_child_b_', RelationType::ONE_TO_MANY],
            ['rs_child_c_', RelationType::MANY_TO_MANY],
        ] as list($prefix, $type)) {
            $child = $this->_createSimpleTable($prefix . $suffix);
            CustomRelation::create([
                'parent_custom_table_id' => $this->parent->id,
                'child_custom_table_id' => $child->id,
                'relation_type' => $type,
            ]);
        }
        $this->refresh();
    }

    /**
     * Drop the per-request caches so the next read sees the saved options and relations.
     *
     * @return void
     */
    protected function refresh(): void
    {
        System::clearCache();
        System::clearRequestSession();
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();
    }

    /**
     * Keep these keys (in this order) as the table's setting.
     *
     * @param array<int, array{key: string, hidden: int}> $rows
     * @return void
     */
    protected function store(array $rows): void
    {
        $this->parent->setOption(RelationSearchSetting::OPTION_NAME, $rows);
        $this->parent->save();
        $this->refresh();
    }

    /**
     * @return array<int, string> the default keys, in the default order
     */
    protected function defaultKeys(): array
    {
        return array_map('strval', RelationSearchSetting::relationTables($this->parent)->keys()->all());
    }

    /**
     * @param array<int, \Exceedone\Exment\Model\RelationTable> $relationTables
     * @return array<int, string>
     */
    protected function keysOf(array $relationTables): array
    {
        $keys = [];
        foreach ($relationTables as $relationTable) {
            $keys[] = RelationSearchSetting::keyOf($relationTable);
        }
        return $keys;
    }

    /**
     * The fixture has three related tables; the others tests rely on it.
     *
     * @return void
     */
    public function testPrecondition(): void
    {
        $this->assertCount(3, $this->defaultKeys());
        $this->assertCount(3, RelationSearchSetting::shown($this->parent));
    }

    /**
     * With nothing set, the related search shows the deduped default list in the default order.
     *
     * @return void
     */
    public function testNothingSetShowsTheDefaultList(): void
    {
        $this->assertSame([], RelationSearchSetting::stored($this->parent));
        $this->assertSame($this->defaultKeys(), $this->keysOf(RelationSearchSetting::shown($this->parent)));
    }

    /**
     * A table whose setting says hidden is left out; the others stay.
     *
     * @return void
     */
    public function testHiddenTableIsNotShown(): void
    {
        $keys = $this->defaultKeys();
        $this->assertGreaterThanOrEqual(2, count($keys));
        $hidden = $keys[1];
        $rows = [];
        foreach ($keys as $key) {
            $rows[] = ['key' => $key, 'hidden' => $key === $hidden ? 1 : 0];
        }
        $this->store($rows);

        $shown = $this->keysOf(RelationSearchSetting::shown($this->parent));
        $this->assertNotContains($hidden, $shown);
        $this->assertSame(array_values(array_diff($keys, [$hidden])), $shown);
    }

    /**
     * The stored order is the order shown.
     *
     * @return void
     */
    public function testStoredOrderIsKept(): void
    {
        $keys = array_reverse($this->defaultKeys());
        $rows = [];
        foreach ($keys as $key) {
            $rows[] = ['key' => $key, 'hidden' => 0];
        }
        $this->store($rows);

        $this->assertSame($keys, $this->keysOf(RelationSearchSetting::shown($this->parent)));
    }

    /**
     * A table the setting does not know yet is shown, after the known ones, in the default order.
     *
     * @return void
     */
    public function testNewRelationIsShownAtTheEnd(): void
    {
        $keys = $this->defaultKeys();
        $last = $keys[count($keys) - 1];
        $this->store([['key' => $last, 'hidden' => 0]]);

        $expected = array_merge([$last], array_values(array_diff($keys, [$last])));
        $rows = RelationSearchSetting::rows($this->parent);
        $this->assertSame($expected, array_column($rows, 'key'));
        $this->assertSame([false, false, false], array_column($rows, 'hidden'));
        $this->assertSame($expected, $this->keysOf(RelationSearchSetting::shown($this->parent)));
    }

    /**
     * A stored key of a relation that is gone does not break the list.
     *
     * @return void
     */
    public function testStoredKeyOfAGoneRelationIsIgnored(): void
    {
        $keys = $this->defaultKeys();
        $this->store([['key' => '999999999-1', 'hidden' => 1]]);

        $this->assertSame($keys, $this->keysOf(RelationSearchSetting::shown($this->parent)));
    }

    /**
     * clean() drops unknown keys and repeats, keeps the order sent, and makes hidden 1 or 0.
     *
     * @return void
     */
    public function testCleanKeepsOnlyKnownKeysOnceInOrder(): void
    {
        $keys = $this->defaultKeys();
        $input = [
            ['key' => $keys[2], 'hidden' => '1'],
            ['key' => 'unknown-1', 'hidden' => '0'],
            ['key' => $keys[0], 'hidden' => '0'],
            ['key' => $keys[2], 'hidden' => '0'],
            ['hidden' => '1'],
            ['key' => $keys[1]],
        ];

        $this->assertSame([
            ['key' => $keys[2], 'hidden' => 1],
            ['key' => $keys[0], 'hidden' => 0],
            ['key' => $keys[1], 'hidden' => 0],
        ], RelationSearchSetting::clean($this->parent, $input));

        $this->assertSame([], RelationSearchSetting::clean($this->parent, null));
    }

    /**
     * The related search page reads the setting: the hidden table is not a box, the others are, in the stored order.
     *
     * @return void
     */
    public function testRelatedSearchBoxesFollowTheSetting(): void
    {
        $keys = array_reverse($this->defaultKeys());
        $hidden = $keys[1];
        $rows = [];
        foreach ($keys as $key) {
            $rows[] = ['key' => $key, 'hidden' => $key === $hidden ? 1 : 0];
        }
        $this->store($rows);

        $controller = new class extends SearchController {
            /**
             * @param mixed $table
             * @return array<int, array<string, mixed>>
             */
            public function targets($table)
            {
                return $this->getSearchTargetRelationTable($table);
            }
        };
        $targets = $controller->targets($this->parent);

        // the table itself comes first
        $this->assertSame($this->parent->table_name, $targets[0]['table_name']);

        $expected = [];
        foreach (RelationSearchSetting::rows($this->parent) as $row) {
            if (!$row['hidden']) {
                $expected[] = $row['relation']->table->table_name;
            }
        }
        $this->assertCount(2, $expected);
        $this->assertSame($expected, array_column(array_slice($targets, 1), 'table_name'));

        $hiddenName = null;
        foreach (RelationSearchSetting::rows($this->parent) as $row) {
            if ($row['hidden']) {
                $hiddenName = $row['relation']->table->table_name;
            }
        }
        $this->assertNotNull($hiddenName);
        $this->assertNotContains($hiddenName, array_column($targets, 'table_name'));
    }

    /**
     * The setting field: one row per related table, ticked when shown; prepare() is clean().
     *
     * @return void
     */
    public function testSettingFieldRendersRowsAndPreparesLikeClean(): void
    {
        $keys = $this->defaultKeys();
        $this->store([['key' => $keys[0], 'hidden' => 1]]);

        $rows = [];
        foreach (RelationSearchSetting::rows($this->parent, false) as $row) {
            $rows[] = [
                'key' => $row['key'],
                'label' => RelationSearchSetting::labelOf($row['relation']),
                'hidden' => $row['hidden'],
            ];
        }
        $this->assertCount(3, $rows);

        $html = view('exment::form.field.relationsearchlist', [
            'viewClass' => ['form-group' => 'form-group', 'label' => 'col-sm-2', 'field' => 'col-sm-8'],
            'label' => 'Related search',
            'id' => 'options_relation_search',
            'name' => 'options[relation_search]',
            'rows' => $rows,
            'help' => null,
            'errors' => new \Illuminate\Support\ViewErrorBag(),
        ])->render();

        foreach ($rows as $i => $row) {
            $this->assertStringContainsString('name="options[relation_search][' . $i . '][key]"', $html);
            $this->assertStringContainsString('value="' . $row['key'] . '"', $html);
        }
        // the first (hidden) row is not ticked, the others are
        $this->assertSame(2, preg_match_all('/value="0" checked/', $html));
        $this->assertSame(3, preg_match_all('/<input type="checkbox"/', $html));

        $field = (new RelationSearchList('relation_search', ['Related search']))->customTable($this->parent);
        $input = [
            ['key' => $keys[1], 'hidden' => '0'],
            ['key' => 'unknown-1', 'hidden' => '1'],
            ['key' => $keys[1], 'hidden' => '1'],
        ];
        $this->assertSame(RelationSearchSetting::clean($this->parent, $input), $field->prepare($input));
        $this->assertSame([['key' => $keys[1], 'hidden' => 0]], $field->prepare($input));
    }

    /**
     * Every language has the new keys.
     *
     * @return void
     */
    public function testEveryLanguageHasTheNewKeys(): void
    {
        $keys = [
            'custom_table.relation_search_label',
            'custom_table.relation_search.select_table',
            'custom_table.relation_search.one_to_many',
            'custom_table.relation_search.many_to_many',
            'custom_table.relation_search.none',
            'custom_table.relation_search.table',
            'custom_table.relation_search.show',
            'custom_table.help.relation_search_label',
        ];
        $dir = dirname(__DIR__, 2) . '/resources/lang';
        foreach (static::LANGS as $lang) {
            $file = $dir . '/' . $lang . '/exment.php';
            $this->assertFileExists($file);
            $messages = include $file;
            $this->assertIsArray($messages);
            foreach ($keys as $key) {
                $value = array_get($messages, $key);
                $this->assertIsString($value, "$lang is missing $key");
                $this->assertNotSame('', $value, "$lang has an empty $key");
            }
        }
    }
}
