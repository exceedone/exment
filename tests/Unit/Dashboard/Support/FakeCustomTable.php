<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard\Support;

use Illuminate\Support\Collection;

/**
 * Stand-in for CustomTable: only `custom_columns` (a Collection of FakeCustomColumn) is
 * read by FilterState / AdvancedFilter.
 */
class FakeCustomTable
{
    /** @var Collection */
    public $custom_columns;
    public $table_name;

    public function __construct(array $columns, string $tableName = 'fake_table')
    {
        $this->custom_columns = collect($columns);
        $this->table_name = $tableName;
    }
}
