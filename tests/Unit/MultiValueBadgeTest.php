<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\ColumnItems\GridCellStyle;
use Exceedone\Exment\DataItems\Grid\KanbanGrid;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * #2159: the values of a multi-value column drawn as shapes (tag, pill,
 * badge, dot, lvl, avatar) are not separated by the separator word on the
 * screen, and the kanban card draws one shape per value.
 *
 * The seeded multi-value select columns are used; their cell style is set
 * in memory only, so nothing is saved.
 */
class MultiValueBadgeTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected const TABLE = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;

    /**
     * @return void
     */
    protected function init()
    {
        $this->initAllTest();
        System::clearRequestSession();
    }

    /**
     * A seeded column read straight from the database, with the given cell
     * style set in memory.
     *
     * @param string $column_name
     * @param string|null $grid_style null: no cell style at all
     * @return CustomColumn
     */
    protected function styledColumn(string $column_name, ?string $grid_style): CustomColumn
    {
        $seeded = CustomColumn::getEloquent($column_name, static::TABLE);
        $custom_column = isset($seeded) ? CustomColumn::query()->find($seeded->id) : null;
        if (!($custom_column instanceof CustomColumn)) {
            $this->fail("Fixture column {$column_name} is missing from the seeded database.");
        }

        $custom_column->setOption('grid_preset', null);
        $custom_column->setOption('grid_style', $grid_style);
        $custom_column->setOption('grid_color', isset($grid_style) ? '#c0392b' : null);

        return $custom_column;
    }

    /**
     * The column item set to a record holding the given values.
     *
     * @param CustomColumn $custom_column
     * @param mixed $value
     * @param bool $grid_column true: rendered the way the data list renders a cell
     * @return CustomItem
     */
    protected function itemOf(CustomColumn $custom_column, $value, bool $grid_column = true): CustomItem
    {
        $custom_table = CustomTable::getEloquent($custom_column->custom_table_id);
        if (!isset($custom_table)) {
            $this->fail('The fixture table is missing.');
        }
        $record = $custom_table->getValueModel();
        $record->setValue($custom_column->column_name, $value);

        // A new item every time: an item builds its styler once and keeps it.
        $item = CustomItem::getItem($custom_column);
        if (!($item instanceof CustomItem)) {
            $this->fail('The column has no column item.');
        }
        if ($grid_column) {
            $item->options(['grid_column' => true]);
        }

        return $item->setCustomValue($record);
    }

    /**
     * The text of the cell, as the browser's textContent reads it.
     *
     * @param string $html
     */
    protected function textContent(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @return void
     */
    public function testBlockStylesHideTheSeparator()
    {
        $this->init();
        $word = strval(exmtrans('common.separate_word'));

        foreach (GridCellStyle::BLOCK_STYLES as $style) {
            $html = strval($this->itemOf($this->styledColumn('select_multiple', $style), ['foo', 'bar'])->html());

            $this->assertStringContainsString(GridCellStyle::separatorHtml($word), $html, "{$style}: the separator is put in its hidden span.");
            $this->assertSame(1, substr_count($html, 'class="' . GridCellStyle::SEPARATOR_CLASS . '"'), "{$style}: one separator between two values.");
            // grouping and "filter in this page" read the text of the cell
            // (an avatar puts the initial of each value before it: "ffoo、bbar")
            $this->assertMatchesRegularExpression('/foo' . preg_quote($word, '/') . '(b)?bar/u', $this->textContent($html), "{$style}: the text of the cell keeps the separator.");
        }
    }

    /**
     * @return void
     */
    public function testOtherStylesKeepTheSeparator()
    {
        $this->init();
        $word = strval(exmtrans('common.separate_word'));

        // no cell style, the styles that do not draw a shape, and a screen that is not the list
        $cases = [
            'none' => $this->itemOf($this->styledColumn('select_multiple', null), ['foo', 'bar']),
            'not the list' => $this->itemOf($this->styledColumn('select_multiple', GridCellStyle::STYLE_TAG), ['foo', 'bar'], false),
        ];
        foreach ([GridCellStyle::STYLE_PLAIN, GridCellStyle::STYLE_TEXT, GridCellStyle::STYLE_MONO, GridCellStyle::STYLE_CELL] as $style) {
            $cases[$style] = $this->itemOf($this->styledColumn('select_multiple', $style), ['foo', 'bar']);
        }

        foreach ($cases as $name => $item) {
            $html = strval($item->html());
            $this->assertStringNotContainsString(GridCellStyle::SEPARATOR_CLASS, $html, "{$name}: the separator is not hidden.");
            $this->assertStringContainsString($word, $html, "{$name}: the separator is printed as before.");
        }
    }

    /**
     * @return void
     */
    public function testSingleValueHasNoSeparator()
    {
        $this->init();

        $html = strval($this->itemOf($this->styledColumn('select_multiple', GridCellStyle::STYLE_TAG), ['foo'])->html());
        $this->assertStringNotContainsString(GridCellStyle::SEPARATOR_CLASS, $html);
        $this->assertStringContainsString('exm-cell-tag', $html);
    }

    /**
     * @return void
     */
    public function testBlockStyleNames()
    {
        foreach (GridCellStyle::BLOCK_STYLES as $style) {
            $this->assertTrue(GridCellStyle::isBlockStyleName($style), $style);
        }
        foreach ([GridCellStyle::STYLE_PLAIN, GridCellStyle::STYLE_TEXT, GridCellStyle::STYLE_MONO, GridCellStyle::STYLE_BAR, GridCellStyle::STYLE_CELL, null, '', ['tag']] as $style) {
            $this->assertFalse(GridCellStyle::isBlockStyleName($style), json_encode($style) ?: '');
        }
    }

    /**
     * The values one by one, each with its own text, not joined.
     *
     * @return void
     */
    public function testTextPartsAreOnePerValue()
    {
        $this->init();

        $parts = $this->itemOf($this->styledColumn('select_valtext_multiple', null), ['foo', '', 'baz'], false)->textParts();
        $this->assertSame([
            ['value' => 'foo', 'text' => 'FOO', 'styled' => true],
            ['value' => 'baz', 'text' => 'BAZ', 'styled' => true],
        ], $parts);
    }

    /**
     * A kanban card field of a multi-value column drawn as shapes carries its
     * values one by one, so the card draws one shape per value.
     *
     * @return void
     */
    public function testKanbanFieldCarriesOnePartPerValue()
    {
        $this->init();

        $custom_column = $this->styledColumn('select_valtext_multiple', null);
        $tag = ['cell' => ['grid_style' => GridCellStyle::STYLE_TAG]];

        $parts = $this->fieldParts($tag, $this->itemOf($custom_column, ['foo', 'bar'], false));
        $this->assertSame([
            ['value' => 'foo', 'text' => 'FOO'],
            ['value' => 'bar', 'text' => 'BAR'],
        ], $parts, 'One part per value, with the value for its color and the text to print.');

        // drawn as one piece: one value, a style that is not a shape, no style
        $this->assertSame([], $this->fieldParts($tag, $this->itemOf($custom_column, ['foo'], false)), 'One value is drawn as before.');
        $this->assertSame([], $this->fieldParts(['cell' => ['grid_style' => GridCellStyle::STYLE_TEXT]], $this->itemOf($custom_column, ['foo', 'bar'], false)), 'A style that is not a shape is drawn as before.');
        $this->assertSame([], $this->fieldParts(['cell' => null], $this->itemOf($custom_column, ['foo', 'bar'], false)), 'A field without a cell style is drawn as before.');

        // a column of one value
        $single = CustomColumn::getEloquent('select', static::TABLE);
        if (!isset($single)) {
            $this->fail('Fixture column select is missing from the seeded database.');
        }
        $this->assertSame([], $this->fieldParts($tag, $this->itemOf($single, 'foo', false)), 'A column of one value is drawn as before.');
    }

    /**
     * @param array<string, mixed> $card_field
     * @param CustomItem $item
     * @return array<int, array<string, string>>
     */
    protected function fieldParts(array $card_field, CustomItem $item): array
    {
        $grid = (new \ReflectionClass(KanbanGrid::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(KanbanGrid::class, 'buildFieldParts');
        $method->setAccessible(true);

        return $method->invoke($grid, $card_field, $item);
    }
}
