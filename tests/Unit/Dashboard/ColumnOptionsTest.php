<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Dashboard\ColumnOptions;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeCustomColumn;

/**
 * The catalogue behind a select / select_valtext column (ColumnOptions::choices): read like
 * the engine, listed in the defined order, narrowed to what the rows hold.
 */
class ColumnOptionsTest extends DashboardUnitTestCase
{
    public function testDefinedChoicesReadTheColumnDefinitionLikeTheEngine()
    {
        $valtext = new FakeCustomColumn('semester', 'select_valtext');
        $valtext->options = ['select_item_valtext' => "1,1学期\r\n 2 , 2学期 \n\n3\n,no key\n4,a,b"];
        $this->assertSame(
            ['1' => '1学期', '2' => '2学期', '3' => '3', '4' => 'a'],
            ColumnOptions::definedChoices($valtext),
            'trimmed; a key alone is its own label; a blank key is no item; the label ends at the next comma, as in the engine'
        );

        $select = new FakeCustomColumn('status', 'select');
        $select->options = ['select_item' => ['open', ' closed ', '']];
        $this->assertSame(['open' => 'open', 'closed' => 'closed'], ColumnOptions::definedChoices($select), 'an array definition; each item is its own label');

        $this->assertNull(ColumnOptions::definedChoices(new FakeCustomColumn('score', 'integer')), 'no catalogue');
        $this->assertNull(ColumnOptions::definedChoices(new FakeCustomColumn('empty', 'select')), 'nothing defined = no catalogue, the rows decide');
        $this->assertNull(ColumnOptions::definedChoices(null));
    }

    public function testDefinedOptionsKeepTheDefinedOrderAndHonourTheScope()
    {
        $defined = ['3' => '3学期', '1' => '1学期', '2' => '2学期'];
        $this->assertSame([
            ['id' => '3', 'name' => '3学期'],
            ['id' => '1', 'name' => '1学期'],
            ['id' => '2', 'name' => '2学期'],
        ], ColumnOptions::definedOptions($defined, null), 'no scope: the whole catalogue, as defined');
        $this->assertSame([
            ['id' => '3', 'name' => '3学期'],
            ['id' => '2', 'name' => '2学期'],
        ], ColumnOptions::definedOptions($defined, ['2', '3', '9']), 'a scope keeps the items its rows hold; a value no longer defined is no choice');
        $this->assertSame([], ColumnOptions::definedOptions($defined, []));
    }

    public function testLabelsComeFromTheDefinition()
    {
        $valtext = new FakeCustomColumn('semester', 'select_valtext');
        $valtext->options = ['select_item_valtext' => "1,1学期\n2,2学期"];
        $this->assertSame(['1' => '1学期'], ColumnOptions::labels($valtext, ['1', '9']), 'a value without a definition has no label');
        $this->assertSame([], ColumnOptions::labels($valtext, []));
        $this->assertSame([], ColumnOptions::labels(new FakeCustomColumn('score', 'integer'), ['5']));
    }

    public function testLabelsOfAMasterNeverQueryATextThatIsNoId()
    {
        // SQL Server fails the query on a text compared with the integer id (?df_school=abc):
        // no id, no query — the base test case fails on any SQL
        $column = new FakeCustomColumn('school', 'select_table');
        $column->select_target_table = new CustomTable();
        $this->assertSame([], ColumnOptions::labels($column, ['abc', '1.5', '-1']));
    }

    public function testLabelsOfSwitchColumnsAreTheirDisplayText()
    {
        $this->assertSame(['1' => 'YES', '0' => 'NO'], ColumnOptions::labels(new FakeCustomColumn('done', 'yesno'), ['0', '1']));

        $boolean = new FakeCustomColumn('gender', 'boolean');
        $boolean->options = ['true_value' => 'm', 'true_label' => '男', 'false_value' => 'f', 'false_label' => '女'];
        $this->assertSame(['m' => '男', 'f' => '女'], ColumnOptions::labels($boolean, ['m', 'f', 'x']), 'a value that is neither keeps no label');
    }

    public function testEndsCompareLikeTheRange()
    {
        // the ends of a range item come from the column's distinct values (ColumnOptions::bounds
        // on an indexed column) instead of a MIN / MAX of CAST over every row
        $this->assertSame(['9', '100'], ColumnOptions::ends(['100', '72', '9'], 'number'), 'numbers as numbers: "9" before "100"');
        $this->assertSame(['1.5', '20'], ColumnOptions::ends(['20', 'abc', '1.5', ''], 'number'), 'text that is no number is skipped');
        $this->assertSame(['2025-12-31', '2026-01-31'], ColumnOptions::ends(['2026-01-31', '2025-12-31', '2026-01-05'], 'date'), 'ISO dates order as text');
        $this->assertSame(['100', '9'], ColumnOptions::ends(['9', '100'], 'text'), 'text compares as text');
        $this->assertSame([null, null], ColumnOptions::ends(['', 'x'], 'number'), 'nothing that compares');
        $this->assertSame([null, null], ColumnOptions::ends([], 'text'));
    }
}
