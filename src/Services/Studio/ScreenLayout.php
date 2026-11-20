<?php

namespace Exceedone\Exment\Services\Studio;

use Exceedone\Exment\Model\CustomFormBlock;

/**
 * Studio, phase 3: the layout of an input screen - rows, and columns in a row.
 *
 * It is kept the way the form setting page keeps it, so a screen made in the
 * Studio is changed there too and the other way round: each item of a form
 * has a row (row_no), a column in the row (column_no) and the width of the
 * column (1 to 4). The data page lays a row out on the 12 grid columns of
 * Bootstrap from the widths (FieldGroupTrait), so the columns stand side by
 * side on a wide screen and one under another on a phone.
 *
 * The builder offers a few ways to split a row, each adding up to 4.
 */
class ScreenLayout
{
    /**
     * The ways to split a row: the width of each column.
     */
    public const PRESETS = [
        [4],
        [2, 2],
        [3, 1],
        [1, 3],
        [2, 1, 1],
        [1, 1, 1, 1],
    ];

    public const MAX_ROWS = 50;

    /**
     * The layout posted with the screen, and where each item is. A screen
     * posted without a layout is one row of one column.
     *
     * @param mixed $layout JSON string or array of rows: [{widths: [2, 2]}, ...]
     * @param array<int, array<string, mixed>> $fields the posted items, each with row and column (from 0)
     * @return array{rows: array<int, array<int, int>>, places: array<int, array{row: int, column: int}>}
     */
    public static function normalize($layout, array $fields): array
    {
        if (is_string($layout)) {
            $layout = json_decode($layout, true);
        }
        $rows = [];
        foreach (is_array($layout) ? array_values($layout) : [] as $row) {
            $widths = is_array($row) ? array_get($row, 'widths') : null;
            $rows[] = is_array($widths) ? array_map('intval', array_values($widths)) : [];
        }
        if (count($rows) === 0) {
            $rows = [[4]];
        }

        $places = [];
        foreach (array_values($fields) as $index => $field) {
            $places[$index] = [
                'row' => is_numeric(array_get($field, 'row')) ? intval($field['row']) : 0,
                'column' => is_numeric(array_get($field, 'column')) ? intval($field['column']) : 0,
            ];
        }
        return ['rows' => $rows, 'places' => $places];
    }

    /**
     * The mistakes of a layout ("layout").
     *
     * @param array{rows: array<int, array<int, int>>, places: array<int, array{row: int, column: int}>} $layout
     * @return array<string, string>
     */
    public static function validate(array $layout): array
    {
        if (count($layout['rows']) > static::MAX_ROWS) {
            return ['layout' => sprintf(exmtrans('studio.error.too_many_rows'), static::MAX_ROWS)];
        }
        foreach ($layout['rows'] as $widths) {
            if (!in_array($widths, static::PRESETS, true)) {
                return ['layout' => exmtrans('studio.error.layout')];
            }
        }
        foreach ($layout['places'] as $place) {
            if (!isset($layout['rows'][$place['row']][$place['column']])) {
                return ['layout' => exmtrans('studio.error.layout')];
            }
        }
        return [];
    }

    /**
     * Put the items of a form block where the layout says. The items are
     * given in the order they were posted, which is the order they are read:
     * row by row, and in a row column by column, top to bottom.
     *
     * @param array<int, int> $columnIds the custom column of each posted item
     * @param array{rows: array<int, array<int, int>>, places: array<int, array{row: int, column: int}>} $layout
     */
    public static function apply(CustomFormBlock $block, array $columnIds, array $layout): void
    {
        $formColumns = $block->custom_form_columns()->get()->keyBy(function ($formColumn) {
            return intval($formColumn->form_column_target_id);
        });
        $order = 1;
        foreach ($columnIds as $index => $columnId) {
            $formColumn = $formColumns->get(intval($columnId));
            if (!isset($formColumn)) {
                continue;
            }
            $place = $layout['places'][$index] ?? ['row' => 0, 'column' => 0];
            $formColumn->row_no = $place['row'] + 1;
            $formColumn->column_no = $place['column'] + 1;
            $formColumn->width = $layout['rows'][$place['row']][$place['column']] ?? 4;
            $formColumn->order = $order++;
            $formColumn->save();
        }
    }

    /**
     * Rows are numbered from 1 with no gap: an empty row of the builder is dropped.
     *
     * @param array{rows: array<int, array<int, int>>, places: array<int, array{row: int, column: int}>} $layout
     * @return array{rows: array<int, array<int, int>>, places: array<int, array{row: int, column: int}>}
     */
    public static function withoutEmptyRows(array $layout): array
    {
        $used = [];
        foreach ($layout['places'] as $place) {
            $used[$place['row']] = true;
        }
        $map = [];
        $rows = [];
        foreach ($layout['rows'] as $index => $widths) {
            if (!isset($used[$index])) {
                continue;
            }
            $map[$index] = count($rows);
            $rows[] = $widths;
        }
        $places = [];
        foreach ($layout['places'] as $index => $place) {
            $places[$index] = ['row' => $map[$place['row']] ?? 0, 'column' => $place['column']];
        }
        return ['rows' => count($rows) > 0 ? $rows : [[4]], 'places' => $places];
    }
}
