<?php

namespace Exceedone\Exment\Database;

use Illuminate\Database\Schema\Blueprint;

class ExtendedBlueprint extends Blueprint
{
    /**
     * set created_user, updated_user, deleted_user
     */
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
    public function dropColumn($columns)
    {
        $columns = is_array($columns) ? $columns : func_get_args();

        $existing = array_filter($columns, fn($col) => \Schema::hasColumn($this->table, $col));

        foreach ($existing as $column) {
            \Schema::dropConstraints($this->table, $column);
        }

        if (empty($existing)) {
            return $this;
        }

        return parent::dropColumn(array_values($existing));
    }
}
