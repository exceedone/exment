<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\ColumnItems\GridCellStyle;
use Exceedone\Exment\ColumnItems\SystemItem;
use Exceedone\Exment\Controllers\CellStylePresetSettingController;
use Exceedone\Exment\DataItems\Grid\KanbanGrid;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Five fixes of the views and the cell styles (ISS02887, GitHub #2151-#2155).
 */
class ViewCellStyleFixesTest extends UnitTestBase
{
    /**
     * #2151: "save this board" makes a system view where user views are not used, and says so.
     */
    public function testTheKindOfViewASavedBoardIs(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $before = System::userview_available();
        try {
            System::userview_available(false);
            System::clearRequestSession();
            $this->assertFalse($table->userViewAvailable());
            $this->assertSame(ViewType::SYSTEM, KanbanGrid::savedViewType($table));

            System::userview_available(true);
            System::clearRequestSession();
            $this->assertTrue($table->userViewAvailable());
            $this->assertSame(ViewType::USER, KanbanGrid::savedViewType($table));

            // a table whose user views are off by config
            config(['exment.userview_unavailable_table' => 'other_table,' . $table->table_name]);
            $this->assertFalse($table->userViewAvailable());
            $this->assertSame(ViewType::SYSTEM, KanbanGrid::savedViewType($table));
        } finally {
            config(['exment.userview_unavailable_table' => '']);
            System::userview_available($before);
            System::clearRequestSession();
        }

        // the message of each kind, in every language
        foreach (['de', 'en', 'es', 'ja', 'ko', 'vi', 'zh_CN'] as $locale) {
            $user = trans('exment::exment.custom_view.message.kanban_view_saved', [], $locale);
            $system = trans('exment::exment.custom_view.message.kanban_view_saved_system', [], $locale);
            $this->assertStringStartsNotWith('exment::', $system, $locale);
            $this->assertNotSame($user, $system, $locale);
            $this->assertStringContainsString('%s', $system, $locale);
        }
    }

    /**
     * #2152: a preset the view set on a system column is applied on the list, as on the cards
     * of a kanban board.
     */
    public function testAPresetOnASystemColumnIsApplied(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $value = $table->getValueModel()->newQueryWithoutScopes()->first();
        $this->assertNotNull($value);

        $plain = SystemItem::getItem($table, 'id')->options(['grid_column' => true])->setCustomValue($value);
        $this->assertStringNotContainsString('exm-cell-', strval($plain->html()));

        $styled = SystemItem::getItem($table, 'id')->options(['grid_column' => true, 'grid_preset' => 'sys.flag_badge'])->setCustomValue($value);
        $html = strval($styled->html());
        $this->assertStringContainsString('class="exm-cell-badge"', $html);
        $this->assertStringContainsString(strval($value->id), $html);

        // not a list of a view (a form, a show page): as before
        $other = SystemItem::getItem($table, 'id')->options(['grid_preset' => 'sys.flag_badge'])->setCustomValue($value);
        $this->assertStringNotContainsString('exm-cell-', strval($other->html()));

        // a preset that does not exist: as before
        $this->assertNull(GridCellStyle::fromPreset('sys.no_such_preset'));
        $this->assertNull(GridCellStyle::fromPreset(null));
    }

    /**
     * #2153: the preview of "plain" is what the list shows: the icon, no color, no weight.
     */
    public function testThePreviewOfPlainIsTheList(): void
    {
        $html = CellStylePresetSettingController::sampleHtml(['name' => 'Plain test', 'options' => [
            'grid_style' => GridCellStyle::STYLE_PLAIN, 'grid_color' => '#c0392b', 'grid_icon' => 'fa-star', 'grid_font_weight' => '700',
        ]]);
        $this->assertStringContainsString('fa-star', $html);
        $this->assertStringContainsString('Plain test', $html);
        $this->assertStringNotContainsString('#c0392b', $html);
        $this->assertStringNotContainsString('font-weight', $html);

        // the list: the same
        $column = $this->column('text', ['grid_style' => GridCellStyle::STYLE_PLAIN, 'grid_color' => '#c0392b', 'grid_icon' => 'fa-star', 'grid_font_weight' => '700']);
        $list = GridCellStyle::make($column);
        $this->assertNotNull($list);
        $wrapped = $list->wrap('Plain test', 'Plain test', 'Plain test');
        $this->assertStringNotContainsString('#c0392b', $wrapped);
        $this->assertStringContainsString('fa-star', $wrapped);

        // the text style keeps its color
        $text = CellStylePresetSettingController::sampleHtml(['name' => 'Text', 'options' => ['grid_style' => GridCellStyle::STYLE_TEXT, 'grid_color' => '#c0392b']]);
        $this->assertStringContainsString('#c0392b', $text);
    }

    /**
     * #2154: the avatar takes its color from the stored value (the per-value colors are set by
     * value), its initial from the label.
     */
    public function testTheAvatarColorComesFromTheValue(): void
    {
        $column = $this->column('select_valtext', [
            'select_item_valtext' => "foo,FOO\nbar,BAR",
            'grid_style' => GridCellStyle::STYLE_AVATAR,
            'grid_value_colors' => "foo,#ff0000\nbar,#00ff00",
            'grid_color' => '#006400',
        ]);
        $style = GridCellStyle::make($column);
        $this->assertNotNull($style);
        $foo = $style->wrap('FOO', 'foo', 'FOO');
        $this->assertStringContainsString('background-color:#ff0000', $foo);
        $this->assertStringContainsString('>F</span>', $foo, 'the initial of the label');
        $this->assertStringContainsString('background-color:#00ff00', $style->wrap('BAR', 'bar', 'BAR'));
        // a value without a color of its own: the color of the preset
        $this->assertStringContainsString('background-color:#006400', $style->wrap('BAZ', 'baz', 'BAZ'));
    }

    /**
     * #2155: the border of "fill the whole cell" is drawn inside the cell, so a pinned column
     * scrolled over it hides it.
     */
    public function testTheBorderOfAFilledCellIsDrawnInside(): void
    {
        $style = GridCellStyle::make($this->column('text', [
            'grid_style' => GridCellStyle::STYLE_CELL, 'grid_bg_color' => '#ff1493', 'grid_border_color' => '#0000ff',
        ]));
        $this->assertNotNull($style);
        $td = $style->tdStyle();
        $this->assertSame('#ff1493', $td['background-color']);
        $this->assertSame('inset 0 0 0 1px #0000ff', $td['box-shadow']);
        $this->assertArrayNotHasKey('border-color', $td);
    }

    /**
     * A column of a table that is never saved: only its type and options are read.
     *
     * @param array<string, mixed> $options
     */
    protected function column(string $type, array $options): CustomColumn
    {
        $column = new CustomColumn();
        $column->column_type = $type;
        $column->column_name = 'claude_fix_' . $type;
        $column->options = $options;
        return $column;
    }
}
