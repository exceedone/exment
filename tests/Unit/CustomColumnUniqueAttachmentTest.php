<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;

/**
 * Test cases: "unique" option is meaningless for attachment(image, file) column.
 * - CustomColumn saving hook removes "unique" option for attachment columns.
 * - getColumnsSelectOptions with "ignore_attachment" excludes attachment columns
 *   (used by multi unique key setting on CustomTableController).
 */
class CustomColumnUniqueAttachmentTest extends UnitTestBase
{
    use DatabaseTransactions;

    public const TARGET_TABLE = 'information';

    /**
     * Create and save custom column to TARGET_TABLE.
     *
     * @param string $column_type
     * @param string $column_name
     * @param array<string, mixed> $options
     * @return CustomColumn
     */
    protected function createColumn($column_type, $column_name, $options = [])
    {
        $custom_table = CustomTable::getEloquent(static::TARGET_TABLE);
        $custom_column = new CustomColumn([
            'custom_table_id' => $custom_table->id,
            'column_name' => $column_name,
            'column_view_name' => $column_name,
            'column_type' => $column_type,
            'options' => $options,
        ]);
        $custom_column->save();

        return $custom_column;
    }

    /**
     * Get options json saved in database directly (not via model, to check real saved value).
     *
     * @param CustomColumn $custom_column
     * @return array<string, mixed>
     */
    protected function getSavedOptions(CustomColumn $custom_column)
    {
        $json = \DB::table('custom_columns')->where('id', $custom_column->id)->value('options');
        return json_decode($json, true) ?? [];
    }


    // Saving hook: remove unique option for attachment column ----------------------------------------------------

    /**
     * Create image column with unique=1, then unique is removed on save.
     *
     * @return void
     */
    public function testUniqueRemovedOnCreateImageColumn()
    {
        $custom_column = $this->createColumn(ColumnType::IMAGE, 'utest_uniq_image', ['unique' => 1]);

        $this->assertArrayNotHasKey('unique', $this->getSavedOptions($custom_column));
    }

    /**
     * Create file column with unique=1, then unique is removed on save.
     *
     * @return void
     */
    public function testUniqueRemovedOnCreateFileColumn()
    {
        $custom_column = $this->createColumn(ColumnType::FILE, 'utest_uniq_file', ['unique' => 1]);

        $this->assertArrayNotHasKey('unique', $this->getSavedOptions($custom_column));
    }

    /**
     * Create text column with unique=1, then unique is kept on save.
     *
     * @return void
     */
    public function testUniqueKeptOnTextColumn()
    {
        $custom_column = $this->createColumn(ColumnType::TEXT, 'utest_uniq_text', ['unique' => 1]);

        $options = $this->getSavedOptions($custom_column);
        $this->assertArrayHasKey('unique', $options);
        $this->assertTrue(boolval($options['unique']));
    }

    /**
     * Image column already saved with unique=1 in database (ex. saved before this feature),
     * then unique is removed on next save.
     * *Test for prepareJson: missing key is restored from original, so hook must set null, not forget.
     *
     * @return void
     */
    public function testUniqueRemovedOnUpdateExistingImageColumn()
    {
        $custom_column = $this->createColumn(ColumnType::IMAGE, 'utest_uniq_image_exists');

        // inject unique=1 directly to database, bypassing model saving hook
        $options = $this->getSavedOptions($custom_column);
        $options['unique'] = '1';
        \DB::table('custom_columns')->where('id', $custom_column->id)->update(['options' => json_encode($options)]);

        // reload fresh model, and check injected value exists
        /** @var CustomColumn $reloaded */
        $reloaded = CustomColumn::query()->find($custom_column->id);
        $this->assertTrue(boolval($reloaded->getOption('unique')));

        // save again(no other changes), then unique is removed
        $reloaded->save();

        $this->assertArrayNotHasKey('unique', $this->getSavedOptions($reloaded));
    }

    /**
     * Other options are not broken when unique is removed for attachment column.
     *
     * @return void
     */
    public function testOtherOptionsKeptWhenUniqueRemoved()
    {
        $custom_column = $this->createColumn(ColumnType::IMAGE, 'utest_uniq_image_opts', [
            'unique' => 1,
            'help' => 'help text',
            'dropzone_title' => 'drop here',
        ]);

        $options = $this->getSavedOptions($custom_column);
        $this->assertArrayNotHasKey('unique', $options);
        $this->assertMatch(array_get($options, 'help'), 'help text');
        $this->assertMatch(array_get($options, 'dropzone_title'), 'drop here');
    }

    /**
     * Update other option of image column with unique=1 injected,
     * then unique is removed and updated option is saved.
     *
     * @return void
     */
    public function testUniqueRemovedOnUpdateOtherOption()
    {
        $custom_column = $this->createColumn(ColumnType::IMAGE, 'utest_uniq_image_upd');

        $options = $this->getSavedOptions($custom_column);
        $options['unique'] = '1';
        \DB::table('custom_columns')->where('id', $custom_column->id)->update(['options' => json_encode($options)]);

        /** @var CustomColumn $reloaded */
        $reloaded = CustomColumn::query()->find($custom_column->id);
        $reloaded->setOption('help', 'updated help');
        $reloaded->save();

        $options = $this->getSavedOptions($reloaded);
        $this->assertArrayNotHasKey('unique', $options);
        $this->assertMatch(array_get($options, 'help'), 'updated help');
    }

    /**
     * Change column_type from TEXT(with unique=1) to IMAGE, then unique is removed on save.
     * *This path is not reachable from the UI (column_type is hidden on edit form),
     *  but can happen via import/API. The saving hook must handle this correctly.
     *
     * @return void
     */
    public function testUniqueRemovedOnChangeColumnTypeToAttachment()
    {
        // create text column with unique=1, and verify unique is kept (baseline)
        $custom_column = $this->createColumn(ColumnType::TEXT, 'utest_uniq_change_type', ['unique' => 1]);
        $this->assertTrue(boolval(array_get($this->getSavedOptions($custom_column), 'unique')), 'unique is not saved on text column.');

        // change column_type to IMAGE and save
        $custom_column->column_type = ColumnType::IMAGE;
        $custom_column->save();

        $this->assertArrayNotHasKey('unique', $this->getSavedOptions($custom_column));
    }


    // Multi unique key select options: ignore attachment columns ----------------------------------------------------

    /**
     * Get select options with same parameter as multi unique key setting
     * (CustomTableController::formMultiColumn).
     *
     * @param bool $ignore_attachment
     * @return array<mixed>
     */
    protected function getMultiUniqueSelectOptions($ignore_attachment)
    {
        // create text, image, file columns
        $this->createColumn(ColumnType::TEXT, 'utest_sel_text');
        $this->createColumn(ColumnType::IMAGE, 'utest_sel_image');
        $this->createColumn(ColumnType::FILE, 'utest_sel_file');

        // clear cache, and get fresh table
        System::clearCache();
        $custom_table = CustomTable::getEloquent(static::TARGET_TABLE);

        $selectOptions = [
            'include_system' => false,
            'include_parent_id' => true,
            'ignore_many_to_many' => true,
        ];
        if ($ignore_attachment) {
            $selectOptions['ignore_attachment'] = true;
        }

        return $custom_table->getColumnsSelectOptions($selectOptions);
    }

    /**
     * Multi unique key select options not have image and file column.
     *
     * @return void
     */
    public function testMultiUniqueSelectOptionsExcludeAttachment()
    {
        $options = $this->getMultiUniqueSelectOptions(true);

        $this->assertTrue(in_array('utest_sel_text', $options), 'text column is not contained.');
        $this->assertFalse(in_array('utest_sel_image', $options), 'image column is contained.');
        $this->assertFalse(in_array('utest_sel_file', $options), 'file column is contained.');
    }

    /**
     * Without ignore_attachment (ex. compare columns setting), select options have image and file column.
     *
     * @return void
     */
    public function testSelectOptionsDefaultContainAttachment()
    {
        $options = $this->getMultiUniqueSelectOptions(false);

        $this->assertTrue(in_array('utest_sel_text', $options), 'text column is not contained.');
        $this->assertTrue(in_array('utest_sel_image', $options), 'image column is not contained.');
        $this->assertTrue(in_array('utest_sel_file', $options), 'file column is not contained.');
    }
}
