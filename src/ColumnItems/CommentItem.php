<?php

namespace Exceedone\Exment\ColumnItems;

use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;

class CommentItem extends SystemItem
{
    /**
     * constructor
     */
    public function __construct($custom_table, $custom_value)
    {
        $this->custom_table = $custom_table;
        $this->setCustomValue($custom_value);

        $this->column_name = SystemColumn::COMMENT;
        $this->label = exmtrans("common.$this->column_name");
    }

    public static function getItem(...$args)
    {
        list($custom_table, $custom_value) = $args + [null, null];
        return new self($custom_table, $custom_value);
    }

    /**
     * Set where query for grid filter. 
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder|\Illuminate\Database\Eloquent\Builder $query
     * @param mixed $input
     * @return void
     */
    public function getAdminFilterWhereQuery($query, $input)
    {
        $tableName = getDBTableName($this->custom_table);
        $tableNameComment = getDBTableName(SystemTableName::COMMENT);
        $columnName = CustomColumn::getEloquent('comment_detail', SystemTableName::COMMENT)->getQueryKey();

        $query->whereExists(function ($subQuery) use ($input, $tableName, $tableNameComment, $columnName) {
            $subQuery->select(\DB::raw(1))
                ->from($tableNameComment)
                ->whereColumn("{$tableNameComment}.parent_id", "{$tableName}.id")
                ->where("{$tableNameComment}.parent_type", $this->custom_table->table_name)
                ->where("{$tableNameComment}.{$columnName}", 'LIKE', "%{$input}%")
                ->whereNull('deleted_at');
        });
    }
}
