<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;

/**
 * The comments of the exported data, as one more sheet (or one more csv file).
 *
 * A comment is a data of the system table "comment" whose parent_id and parent_type point to the
 * commented data, so this is the output of a child table with two more columns: who wrote the
 * comment and who updated it.
 *
 * The sheet is for reading only: the import ignores it.
 */
class CommentProvider extends DefaultTableProvider
{
    // @phpstan-ignore-next-line
    public function __construct($args = [])
    {
        // the commented table
        $parent_table = array_get($args, 'custom_table');

        parent::__construct([
            'custom_table' => CustomTable::getEloquent(SystemTableName::COMMENT),
            'grid' => array_get($args, 'grid'),
            'parent_table' => isset($parent_table) ? $parent_table->table_name : null,
        ]);
    }

    /**
     * get data name
     */
    // @phpstan-ignore-next-line
    public function name()
    {
        return SystemTableName::COMMENT;
    }

    /**
     * Not output to the template for import, and when the table of the comments or the commented table is missing.
     *
     * @return boolean
     */
    public function isOutput()
    {
        return !$this->template && isset($this->custom_table) && isset($this->parent_table);
    }

    /**
     * get column info
     * @return mixed list. first:fixed column id, suuid, parent_id, parent_type. second: custom columns: third: created_at, updated_at, created_user, updated_user
     */
    protected function getColumnDefines()
    {
        list($firstColumns, $custom_columns) = parent::getColumnDefines();

        return [$firstColumns, $custom_columns, ['created_at', 'updated_at', 'created_user', 'updated_user']];
    }
}
