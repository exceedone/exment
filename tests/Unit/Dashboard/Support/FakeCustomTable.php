<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard\Support;

use Illuminate\Support\Collection;

/**
 * Stand-in for CustomTable: only `custom_columns` is read by the filter services.
 */
class FakeCustomTable
{
    /** @var Collection<int, FakeCustomColumn> */
    public $custom_columns;
    /** @var string */
    public $table_name;

    /**
     * @param FakeCustomColumn[] $columns
     */
    public function __construct(array $columns, string $tableName = 'fake_table')
    {
        $this->custom_columns = collect($columns);
        $this->table_name = $tableName;
    }
}
