<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;

class CCustomRelationTest extends ExmentKitTestCase
{
    use ExmentKitPrepareTrait;

    /**
     * pre-excecute process before test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->login();
    }

    /**
     * prepare test table.
     */
    public function testPrepareTestTable()
    {
        $this->createCustomTable('exmenttest_contract', 1, 1);
        $this->createCustomTable('exmenttest_contract_relation', 1, 1);
    }

    /**
     * Check custom relation display.
     */
    public function testDisplayRelationSetting()
    {
        // Check custom column form
        $this->visit(admin_url('relation/exmenttest_contract'))
                ->seePageIs(admin_url('relation/exmenttest_contract'))
                ->see('リレーション設定')
                ->seeInElement('th', '親テーブル')
                ->seeInElement('th', '子テーブル')
                ->seeInElement('th', 'リレーション種類')
                ->seeInElement('th', '操作')
                ->visit(admin_url('relation/exmenttest_contract/create'))
                ->seeInElement('h1', 'リレーション設定')
                ->seeInElement('h3[class=box-title]', '作成')
                ->seeInElement('label', '親テーブル')
                ->seeInElement('label', '子テーブル')
                ->seeInElement('label', 'リレーション種類')
        ;
    }

    /**
     * Create & edit custom relation --one to many--.
     */
    public function testAddRelationOneToManySuccess()
    {
        $row = CustomTable::where('table_name', 'exmenttest_contract_relation')->first();
        $child_id = array_get($row, 'id');

        $pre_cnt = CustomRelation::count();

        // Create custom relation
        $this->visit(admin_url('relation/exmenttest_contract/create'))
                ->select($child_id, 'child_custom_table_id')
                ->select('1', 'relation_type')
                ->press('admin-submit')
                ->seePageIs(admin_url('relation/exmenttest_contract'))
                ->seeInElement('td', 'Exmenttest Contract Relation')
                ->seeInElement('td', '1対多')
                ->assertEquals($pre_cnt + 1, CustomRelation::count())
        ;

        $row = CustomRelation::orderBy('id', 'desc')->first();
        $id = array_get($row, 'id');

        // Edit custom relation
        $this->visit(admin_url('relation/exmenttest_contract/'. $id . '/edit'))
                ->seeInElement('span[class=child_custom_table_id]', 'Exmenttest Contract Relation')
                ->seeInElement('span[class=relation_type]', '1対多')
                ->select('2', 'relation_type')
                ->press('admin-submit')
                ->seePageIs(admin_url('relation/exmenttest_contract'))
                ->seeInElement('td', 'Exmenttest Contract Relation')
                ->seeInElement('td', '多対多')
        ;
    }

    /**
     * Create custom relation --many to many--.
     */
    public function testAddRelationManyToManySuccess()
    {
        $row = CustomTable::where('table_name', 'user')->first();
        $child_id = array_get($row, 'id');

        $pre_cnt = CustomRelation::count();

        // Create custom relation
        $this->visit(admin_url('relation/exmenttest_contract/create'))
                ->select($child_id, 'child_custom_table_id')
                ->select('2', 'relation_type')
                ->press('admin-submit')
                ->seePageIs(admin_url('relation/exmenttest_contract'))
                ->seeInElement('td', 'ユーザー')
                ->seeInElement('td', '多対多')
                ->assertEquals($pre_cnt + 1, CustomRelation::count())
        ;

        $row = CustomRelation::orderBy('id', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom relation
        $this->visit(admin_url('relation/exmenttest_contract/'. $id . '/edit'))
                ->seeInElement('span[class=child_custom_table_id]', 'ユーザー')
                ->seeInElement('span[class=relation_type]', '多対多')
        ;
    }

    /**
     * Drop custom relation.
     */
    public function testDropOneLineTextColumn()
    {
        /** @var CustomTable|null $custom_table */
        $custom_table = CustomTable::where('table_name', 'exmenttest_contract')->first();
        $table_id = $custom_table->id;
        /** @var CustomRelation|null $row */
        $row = CustomRelation::where('parent_custom_table_id', $table_id)->first();

        $pre_cnt = CustomRelation::count();

        if ($row) {
            // Delete custom relation
            $this->delete('/admin/relation/exmenttest_contract/'. $row->id)
                ->assertEquals($pre_cnt - 1, CustomRelation::count())
            ;
        }
    }
}
