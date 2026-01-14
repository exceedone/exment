<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\MultisettingType;
use Exceedone\Exment\Enums\ValueType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;

class MultiUniqueValueTest extends UnitTestBase
{
    public function testUniqueColumn2()
    {
        $custom_table = $this->initUniqueValueTest(['text', 'decimal']);

        $result = $custom_table->validatorUniques(['value' => ['text' => 'hogehoge_unique', 'decimal' => 1.234 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotUniqueColumn2()
    {
        $custom_table = $this->initUniqueValueTest(['text', 'decimal']);

        $duplicate = CustomTable::getEloquent('child_table')->getValueModel(1);

        $result = $custom_table->validatorUniques(['value' => [
            'text' => $duplicate->getValue('text'), 
            'decimal' => $duplicate->getValue('decimal')
        ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testUniqueColumn3()
    {
        $custom_table = $this->initUniqueValueTest(['user', 'date', 'integer']);

        $result = $custom_table->validatorUniques(['value' => ['user' => 1, 'date' => '2000-03-04', 'integer' => 200 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotUniqueColumn3()
    {
        $custom_table = $this->initUniqueValueTest(['user', 'date', 'integer']);

        $duplicate = CustomTable::getEloquent('child_table')->getValueModel(1);

        $result = $custom_table->validatorUniques(['value' => [
            'user' => $duplicate->getValue('user', ValueType::PURE_VALUE), 
            'date' => $duplicate->getValue('date'), 
            'integer' => $duplicate->getValue('integer') 
        ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testUniqueColumn3withParent()
    {
        $custom_table = $this->initUniqueValueTest(['user', 'parent_id', 'integer']);

        $result = $custom_table->validatorUniques(['parent_id' => 1, 'value' => ['user' => 1, 'integer' => 1234567890 ]]);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotUniqueColumn3withParent()
    {
        $custom_table = $this->initUniqueValueTest(['odd_even', 'currency', 'parent_id']);

        $duplicate = CustomTable::getEloquent('child_table')->getValueModel(1);

        $result = $custom_table->validatorUniques([
            'parent_id' => $duplicate->parent_id, 
            'value' => [
                'odd_even' => $duplicate->getValue('odd_even'), 
                'currency' => $duplicate->getValue('currency') 
            ]]);

        $this->assertTrue(count($result) > 0);
    }

    public function testUniqueColumn3withParentUpdate()
    {
        $custom_table = $this->initUniqueValueTest(['odd_even', 'currency', 'parent_id']);

        $original = CustomTable::getEloquent('child_table')->getValueModel(1);

        $result = $custom_table->validatorUniques([
            'value' => [
                'odd_even' => $original->getValue('odd_even'), 
                'currency' => $original->getValue('currency'),
                'integer' => 1212 
            ]], $original);

        $this->assertTrue(count($result) == 0);
    }

    public function testNotUniqueColumn3withParentUpdate()
    {
        $custom_table = $this->initUniqueValueTest(['odd_even', 'currency', 'parent_id']);

        $original = CustomTable::getEloquent('child_table')->getValueModel(1);
        $duplicate = CustomTable::getEloquent('child_table')->getValueModel()
            ->whereNot('id', $original->id)
            ->where('parent_id', $original->parent_id)
            ->first();

        $result = $custom_table->validatorUniques([
            'value' => [
                'odd_even' => $duplicate->getValue('odd_even'), 
                'currency' => $duplicate->getValue('currency'),
                'integer' => 1212 
            ]], $original);

        $this->assertTrue(count($result) > 0);
    }

    protected function initUniqueValueTest(array $column_names): CustomTable
    {
        $custom_table = CustomTable::getEloquent('child_table');

        $column_ids = [];
        foreach ($column_names as $index => $column_name) {
            if ($column_name == 'parent_id') {
                $column_ids[] = $column_name;
            } else {
                $custom_column = CustomColumn::getEloquent($column_name, $custom_table);
                $column_ids[] = $custom_column->id;
            }
        }

        $custom_column_multi = new CustomColumnMulti();
        $custom_column_multi->custom_table_id = $custom_table->id;
        $custom_column_multi->multisetting_type = MultisettingType::MULTI_UNIQUES;
        $custom_column_multi->unique1 = $column_ids[0];
        $custom_column_multi->unique2 = $column_ids[1];
        if (count($column_ids) > 2) {
            $custom_column_multi->unique3 = $column_ids[2];
        }
        $custom_column_multis[] = $custom_column_multi;

        System::clearRequestSession();
        System::requestSession(sprintf(Define::SYSTEM_KEY_SESSION_ALL_RECORDS, CustomColumnMulti::getTableName()), collect($custom_column_multis));

        return $custom_table;
    }
}
