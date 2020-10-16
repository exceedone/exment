<?php

namespace Exceedone\Exment\Database;

use Illuminate\Database\Schema\Blueprint;

class ExtendedBlueprint extends Blueprint
{
    /**
     * set created_user, updated_user, deleted_user
     */
    public function timeusers($precision = 0)
    {
        $this->unsignedInteger('created_user_id', $precision)->nullable();
        $this->unsignedInteger('updated_user_id', $precision)->nullable();
    }


    /**
     * Indicate that the given columns should be dropped.
     * *If sql server, first drop default.
     *
     * @param  array|mixed  $columns
     */
    public function dropColumn($columns)
    {
        $columns = is_array($columns) ? $columns : func_get_args();

        foreach ($columns as $column) {
            \Schema::dropConstraints($this->table, $column);
        }

        return parent::dropColumn($columns);
    }
}
