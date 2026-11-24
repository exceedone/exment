<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\File as ExmentFile;

/**
 * Reads together the records and files that the rows of a page point at, so that a cell does not
 * read its own (ISS00413). Used by the list of a table, the child tables of a record, Kanban and
 * the dashboard.
 */
class ReferencePrefetcher
{
    /**
     * Read together the records the page's columns point at: a select_table, user or
     * organization column, a column of the record it points at (through that pivot column),
     * the parent, and the files of a file or image column. One query per table for the page,
     * not one per row and column. Only what the view shows: a small view reads nothing more.
     *
     * @param CustomTable $custom_table  the table of the rows
     * @param \Illuminate\Support\Collection<int, mixed>|mixed $rows  the custom values (anything else: nothing is read)
     * @param iterable<mixed> $view_columns  the CustomViewColumns shown
     * @param bool $selectTables  false: the select_table columns are read already (setSelectTableValues)
     * @return void
     */
    public static function prefetch(CustomTable $custom_table, $rows, $view_columns, bool $selectTables = true): void
    {
        if (!($rows instanceof \Illuminate\Support\Collection) || $rows->isEmpty()) {
            return;
        }

        /** @var array<int|string, CustomColumn> $columns  select_table, user, organization */
        $columns = [];
        /** @var array<int|string, CustomColumn> $files  file, image */
        $files = [];
        $parent = false;
        foreach ($view_columns as $view_column) {
            if (!($view_column instanceof CustomViewColumn)) {
                continue;
            }
            $pivot = $view_column->view_pivot_column_id ?? null;
            if (isMatchString($pivot, SystemColumn::PARENT_ID) || $view_column->view_column_type == ConditionType::PARENT_ID) {
                $parent = true;
                continue;
            }
            if (is_numeric($pivot)) {
                // through the select_table column: the records it points at
                $column = CustomColumn::getEloquent($pivot);
            } elseif (!isMatchString($view_column->view_column_table_id, $custom_table->id)) {
                // a column of the parent, read through the relation
                $parent = true;
                continue;
            } else {
                $column = $view_column->custom_column;
                if ($column instanceof CustomColumn && ColumnType::isAttachment($column->column_type)) {
                    $files[$column->id] = $column;
                    continue;
                }
            }
            if (!$selectTables && !is_numeric($pivot)) {
                continue;
            }
            if ($column instanceof CustomColumn && ColumnType::isSelectTable($column->column_type) && isset($column->select_target_table)) {
                $columns[$column->id] = $column;
            }
        }

        foreach ($columns as $column) {
            $values = [];
            foreach ($rows as $row) {
                $values[] = array_get($row, "value.{$column->column_name}");
            }
            $values = array_filter($values);
            if (!empty($values)) {
                $column->select_target_table->setCustomValueModels($values);
            }
        }

        if (!empty($files)) {
            $paths = [];
            foreach ($rows as $row) {
                foreach ($files as $column) {
                    $paths[] = array_get($row, "value.{$column->column_name}");
                }
            }
            ExmentFile::prefetch($paths);
        }

        if ($parent && $rows instanceof \Illuminate\Database\Eloquent\Collection) {
            $custom_table->setRelationValues($rows);
        }
    }
}
