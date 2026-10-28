<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\ColumnItems\GridCellStyle;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CellStylePreset;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The cell style fixes of the data list and the kanban board.
 *
 * Every test drives the real code (GridCellStyle, CellStylePreset, the
 * CustomColumn saving hook, CustomItem::html) and pins one reported bug: it
 * fails on the code before the fix and passes after it. Fixture rows and
 * columns come from the seeded test database; nothing here creates a table,
 * so every write is rolled back with the transaction.
 */
class CellStyleFixTest extends UnitTestBase
{
    use DatabaseTransactions;

    /**
     * A grid_style value that would close the class attribute and add an
     * event handler if it were printed as it is.
     */
    protected const HOSTILE_STYLE = 'x" onmouseover="alert(1)';

    /**
     * @return void
     */
    protected function init()
    {
        $this->initAllTest();
        System::clearRequestSession();
    }

    /**
     * A column that exists only in memory, carrying the given cell style
     * options. Nothing is saved.
     *
     * @param array<string, mixed> $options
     * @param string $column_type
     * @return CustomColumn
     */
    protected function memoryColumn(array $options, string $column_type = ColumnType::TEXT): CustomColumn
    {
        $custom_column = new CustomColumn();
        $custom_column->custom_table_id = $this->fixtureTable(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS)->id;
        $custom_column->column_type = $column_type;
        $custom_column->column_name = 'cell_style_fix_test';
        $custom_column->options = $options;

        return $custom_column;
    }

    /**
     * @param string $table_name
     * @return CustomTable
     */
    protected function fixtureTable(string $table_name): CustomTable
    {
        $custom_table = CustomTable::getEloquent($table_name);
        if (!isset($custom_table)) {
            $this->fail("Fixture table {$table_name} is missing from the seeded database.");
        }

        return $custom_table;
    }

    /**
     * A seeded column read straight from the database, so whatever is set on
     * it in a test does not leak into the shared cache.
     *
     * @param string $table_name
     * @param string $column_name
     * @return CustomColumn
     */
    protected function freshColumn(string $table_name, string $column_name): CustomColumn
    {
        $seeded = CustomColumn::getEloquent($column_name, $table_name);
        $custom_column = isset($seeded) ? CustomColumn::query()->find($seeded->id) : null;
        if (!($custom_column instanceof CustomColumn)) {
            $this->fail("Fixture column {$table_name}.{$column_name} is missing from the seeded database.");
        }

        return $custom_column;
    }

    /**
     * The options of a column exactly as stored, bypassing every cache.
     *
     * @param CustomColumn $custom_column
     * @return array<string, mixed>
     */
    protected function storedOptions(CustomColumn $custom_column): array
    {
        $json = \DB::table('custom_columns')->where('id', $custom_column->id)->value('options');
        $options = json_decode_ex($json, true);

        return is_array($options) ? $options : [];
    }

    /**
     * The styler of a column, which the test expects to exist.
     *
     * @param CustomColumn $custom_column
     * @return GridCellStyle
     */
    protected function styler(CustomColumn $custom_column): GridCellStyle
    {
        $cell_style = GridCellStyle::make($custom_column);
        if (!isset($cell_style)) {
            $this->fail('The column was expected to be styled.');
        }

        return $cell_style;
    }

    // High #2: grid_style is printed into a class attribute ---------------------------

    /**
     * A grid_style that is not one of the styles GridCellStyle draws must not
     * reach the markup: it used to be concatenated into class="exm-cell-..."
     * unescaped, so a table administrator could plant a script that ran for
     * everyone opening the list.
     *
     * @return void
     */
    public function testUnknownGridStyleIsDrawnPlain()
    {
        $this->init();

        $cell_style = $this->styler($this->memoryColumn([
            'grid_style' => static::HOSTILE_STYLE,
            'grid_color' => '#c0392b',
        ]));

        $html = $cell_style->wrap('abc', 'abc', 'abc');
        $this->assertStringNotContainsString('onmouseover', $html, 'An unknown grid_style must never be printed into the cell markup.');
        $this->assertSame('abc', $html, 'An unknown grid_style must be read as the plain style.');

        // The kanban card is drawn in the browser from toOptions(), so the
        // refused value must not travel there either.
        $options = $cell_style->toOptions();
        $this->assertSame(GridCellStyle::STYLE_PLAIN, $options['grid_style'], 'toOptions() must hand the browser the style that was actually used.');
        $this->assertStringNotContainsString('onmouseover', strval(json_encode($options)), 'toOptions() must not carry the refused value.');

        // A listed style keeps working.
        $html = $this->styler($this->memoryColumn(['grid_style' => 'pill', 'grid_color' => '#c0392b']))->wrap('abc', 'abc', 'abc');
        $this->assertStringContainsString('class="exm-cell-pill"', $html, 'A listed style must still be drawn.');
    }

    /**
     * Settings posted as arrays used to reach strval(), whose "Array to string
     * conversion" warning turns into an exception: every list using the column
     * and every save of a preset answered with an error page.
     *
     * @return void
     */
    public function testStyleSettingsPostedAsArraysDoNotThrow()
    {
        $this->init();

        $hostile = [
            'grid_style' => ['a'],
            'grid_font_weight' => ['b'],
            'grid_color' => ['c'],
            'grid_icon' => ['d'],
            'grid_value_colors' => ['e'],
        ];

        $cell_style = null;
        try {
            $cell_style = GridCellStyle::make($this->memoryColumn($hostile));
        } catch (\Throwable $ex) {
            $this->fail('Painting a column whose settings are arrays threw ' . get_class($ex) . ': ' . $ex->getMessage());
        }
        $this->assertNull($cell_style, 'Settings that are all refused leave the column unstyled.');

        $filtered = null;
        try {
            $filtered = CellStylePreset::filterOptions($hostile);
        } catch (\Throwable $ex) {
            $this->fail('Filtering preset settings that are arrays threw ' . get_class($ex) . ': ' . $ex->getMessage());
        }
        $this->assertSame([], $filtered, 'Settings that are arrays must all be dropped from a preset.');
    }

    /**
     * Saving a column keeps only the cell style values the renderer accepts.
     * The column screen still posts the raw grid_* fields of columns styled
     * before presets existed, and a template brings whatever another
     * installation stored; both go through CustomColumn::save().
     *
     * @return void
     */
    public function testColumnSaveDropsRefusedStyleSettings()
    {
        $this->init();

        $custom_column = $this->freshColumn(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS, 'text');
        $before = $this->storedOptions($custom_column);
        $custom_column->setOption('grid_style', static::HOSTILE_STYLE);
        $custom_column->setOption('grid_color', 'C0392B');
        $custom_column->setOption('grid_font_weight', ['700']);
        $custom_column->setOption('grid_value_colors', "v,,#bbbbbb\nno color here");
        $custom_column->save();

        $stored = $this->storedOptions($custom_column);
        $this->assertArrayNotHasKey('grid_style', $stored, 'A grid_style the renderer refuses must not be stored.');
        $this->assertSame('#c0392b', $stored['grid_color'] ?? null, 'A color must be stored the way the renderer reads it.');
        $this->assertArrayNotHasKey('grid_font_weight', $stored, 'A font weight posted as an array must not be stored.');
        $this->assertSame('v,,#bbbbbb', $stored['grid_value_colors'] ?? null, 'Per-value lines are stored as parsed: slots in place, unusable lines dropped.');
        foreach (['index_enabled', 'freeword_search'] as $key) {
            $this->assertSame($before[$key] ?? null, $stored[$key] ?? null, "The option {$key} is not a cell style setting and must be left alone.");
        }

        // A bad value already in the database - an older template, a direct
        // edit - is cleaned the next time the column is saved, even when that
        // save does not carry the key: prepareJson() would put it back as it
        // was stored.
        $options = $stored;
        $options['grid_style'] = static::HOSTILE_STYLE;
        \DB::table('custom_columns')->where('id', $custom_column->id)->update(['options' => json_encode($options)]);

        $reloaded = $this->freshColumn(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS, 'text');
        $this->assertSame(static::HOSTILE_STYLE, $reloaded->getOption('grid_style'), 'Fixture: the bad value must be stored before the second save.');
        $without = $reloaded->options;
        unset($without['grid_style']);
        $reloaded->options = $without;
        $reloaded->save();

        $this->assertArrayNotHasKey('grid_style', $this->storedOptions($reloaded), 'A stored grid_style the renderer refuses must not survive the next save.');
    }

    // L4: the per-value color slots are read by position ------------------------------

    /**
     * `value,#color,#background,#border` is read by position. Re-serializing
     * a preset used to close up empty slots, so a background-only line became
     * a text color and a border became a background.
     *
     * @return void
     */
    public function testValueColorSlotsKeepTheirPositions()
    {
        $this->init();

        $text = "v,,#bbbbbb\nw,#cccccc,,#dddddd\nx,,,#eeeeee";

        $filtered = CellStylePreset::filterOptions(['grid_style' => 'pill', 'grid_value_colors' => $text]);
        $this->assertSame($text, $filtered['grid_value_colors'] ?? null, 'Filtering must not move a color into another slot.');

        // Through a stored preset, the way every screen reads it back.
        $preset = new CellStylePreset();
        $preset->preset_name = 'cell style fix test';
        $preset->column_types = [];
        $preset->options = ['grid_style' => 'pill', 'grid_value_colors' => $text];
        $preset->order = 0;
        $preset->save();
        System::clearCache();

        $definition = CellStylePreset::findDefinition($preset->suuid);
        if (!isset($definition)) {
            $this->fail('The preset just saved must be found.');
        }
        $rows = GridCellStyle::parseValueColors(array_get($definition, 'options.grid_value_colors'));
        $this->assertSame(['background' => '#bbbbbb'], $rows['v'] ?? null, 'A background-only line must stay a background.');
        $this->assertSame(['color' => '#cccccc', 'border' => '#dddddd'], $rows['w'] ?? null, 'A border after an empty background must stay a border.');
        $this->assertSame(['border' => '#eeeeee'], $rows['x'] ?? null, 'A border-only line must stay a border.');

        // And painted: the background fills the pill, the text keeps its own color.
        $html = $this->styler($this->memoryColumn(['grid_preset' => $preset->suuid]))->wrap('v', 'v', 'v');
        $this->assertStringContainsString('background-color:#bbbbbb', $html, 'The background of the line must paint the background.');
        $this->assertStringNotContainsString('"color:#bbbbbb', $html, 'The background of the line must not become the text color.');
    }

    // L5: a deleted system preset stays deleted ---------------------------------------

    /**
     * An administrator may delete a shipped "sys." preset. The code's built-in
     * copy used to stand in whenever the row was missing, so the deleted
     * preset kept painting columns and kept being offered in every picker.
     *
     * @return void
     */
    public function testDeletedSystemPresetIsNotReadFromTheBuiltinCopy()
    {
        $this->init();

        $deleted = $this->deleteSystemPreset('status_pill');

        $this->assertNull(CellStylePreset::findDefinition($deleted), 'A deleted system preset must not resolve from the built-in copy.');
        $keys = collect(CellStylePreset::getDefinitions())->pluck('key')->all();
        $this->assertNotContains($deleted, $keys, 'A deleted system preset must not be offered again.');
        $this->assertContains(CellStylePreset::BUILTIN_PREFIX . 'status_badge', $keys, 'The system presets still stored must still be offered.');

        // A column pointing at the deleted preset renders unstyled.
        $this->assertNull(GridCellStyle::make($this->memoryColumn(['grid_preset' => $deleted])), 'A column pointing at a deleted preset must render unstyled.');
    }

    /**
     * The migration shipping a preset added later (2026_09_22_090000) used to
     * seed the whole catalog again, which put back every shipped preset an
     * administrator had deleted since the first seeding.
     *
     * @return void
     */
    public function testSeedingANamedPresetDoesNotRestoreDeletedOnes()
    {
        $this->init();

        $deleted = $this->deleteSystemPreset('status_pill');
        // The preset that migration ships, missing as on an installation
        // seeded before it existed.
        $added_later = $this->deleteSystemPreset('text_plain');

        // What the migration runs.
        CellStylePreset::seedDefaults(['text_plain']);
        System::clearCache();

        $this->assertTrue(CellStylePreset::where('suuid', $added_later)->exists(), 'Seeding a named preset must insert it when it is missing.');
        $this->assertFalse(CellStylePreset::where('suuid', $deleted)->exists(), 'Seeding a named preset must not put back another one an administrator deleted.');
    }

    /**
     * Delete one seeded system preset the way the setting screen does.
     *
     * @param string $name BUILTINS key
     * @return string the suuid it had
     */
    protected function deleteSystemPreset(string $name): string
    {
        $suuid = CellStylePreset::BUILTIN_PREFIX . $name;

        $row = CellStylePreset::where('suuid', $suuid)->first();
        if (!isset($row)) {
            $this->fail("Fixture: the seeded database must hold {$suuid}.");
        }
        $row->delete();
        System::clearCache();

        return $suuid;
    }

    // L8: a reference the viewer may not open --------------------------------------

    /**
     * A select_table value whose target the viewer may not open is printed as
     * "no permission". Its per-value color is keyed on the target id, so
     * painting it told the viewer which record it was.
     *
     * @return void
     */
    public function testHiddenReferenceIsNotPaintedByItsId()
    {
        $this->init();

        // all_columns_table_fortest.select_table_2 points at custom_value_edit,
        // where a user without the "all" permission sees only some of the rows.
        $target_table = $this->fixtureTable(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        list($hidden_id, $visible_id) = $this->findPartlyVisibleRows($target_table);

        $custom_column = $this->freshColumn(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST, 'select_table_2');
        $this->assertSame($target_table->id, optional($custom_column->select_target_table)->id, 'Fixture: select_table_2 must point at custom_value_edit.');
        $custom_column->setOption('grid_preset', null);
        $custom_column->setOption('grid_style', GridCellStyle::STYLE_BADGE);
        $custom_column->setOption('grid_value_colors', "{$hidden_id},#ff0000\n{$visible_id},#00ff00");

        $visible = $this->renderReference($custom_column, $visible_id);
        $this->assertStringContainsString('#00ff00', $visible, 'A reference the viewer may open is still painted by its id.');

        $hidden = $this->renderReference($custom_column, $hidden_id);
        $this->assertStringContainsString(exmtrans('common.message.no_permission'), $hidden, 'Fixture: the hidden reference must print the no-permission text.');
        $this->assertStringNotContainsString('#ff0000', $hidden, 'A reference the viewer may not open must not wear the color of its target id.');
        $this->assertStringNotContainsString('exm-cell-badge', $hidden, 'A reference the viewer may not open must be printed plain.');
    }

    /**
     * Log in as a seeded user who sees part of a table, and return one row id
     * that user cannot see and one they can.
     *
     * @param CustomTable $target_table
     * @return array{0: mixed, 1: mixed}
     */
    protected function findPartlyVisibleRows(CustomTable $target_table): array
    {
        $candidates = [
            TestDefine::TESTDATA_USER_LOGINID_USER2,
            TestDefine::TESTDATA_USER_LOGINID_USER1,
            TestDefine::TESTDATA_USER_LOGINID_DEV_USERB,
            TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC,
            TestDefine::TESTDATA_USER_LOGINID_DEV1_USERD,
        ];

        foreach ($candidates as $login_id) {
            $user = LoginUser::find($login_id);
            if (!isset($user)) {
                continue;
            }
            System::clearCache();
            System::clearRequestSession();
            $this->be($user);

            $all = $target_table->getValueModel()->query()
                ->withoutGlobalScope(CustomValueModelScope::class)->pluck('id')->all();
            $visible = $target_table->getValueModel()->query()->pluck('id')->all();
            $hidden = array_values(array_diff($all, $visible));

            if (count($hidden) > 0 && count($visible) > 0) {
                return [$hidden[0], $visible[0]];
            }
        }

        $this->fail("Fixture: no seeded user sees only part of {$target_table->table_name}.");
    }

    /**
     * One reference rendered the way the data list renders a cell.
     *
     * @param CustomColumn $custom_column
     * @param mixed $target_id
     * @return string
     */
    protected function renderReference(CustomColumn $custom_column, $target_id): string
    {
        System::clearRequestSession();

        $custom_table = CustomTable::getEloquent($custom_column->custom_table_id);
        if (!isset($custom_table)) {
            $this->fail('The table of the select_table column is missing.');
        }
        $record = $custom_table->getValueModel();
        $record->setValue($custom_column->column_name, $target_id);

        // A new item every time: an item builds its styler once and keeps it.
        $item = CustomItem::getItem($custom_column);
        if (!isset($item)) {
            $this->fail('The select_table column has no column item.');
        }
        $item->options(['grid_column' => true]);

        return strval($item->setCustomValue($record)->html());
    }
}
