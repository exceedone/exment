<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard\Support;

use Exceedone\Exment\Model\CustomTable;

/**
 * Stand-in for CustomColumn with the surface the dashboard services read.
 */
class FakeCustomColumn
{
    /** @var string */
    public $column_name;
    /** @var string */
    public $column_view_name;
    /** @var string */
    public $column_type;
    /** @var bool */
    public $index_enabled;
    /** @var CustomTable|null */
    public $select_target_table = null;
    /** @var array<string, mixed> */
    public $options = [];
    /** @var CustomTable the (unsaved) table the column belongs to: FilterValue qualifies its SQL with it (exm__fake) */
    public $custom_table_cache;

    public function __construct(string $name, string $type = 'select', bool $indexed = false, ?string $viewName = null)
    {
        $this->column_name = $name;
        $this->column_view_name = $viewName ?? strtoupper($name);
        $this->column_type = $type;
        $this->index_enabled = $indexed;
        $this->custom_table_cache = new CustomTable();
        $this->custom_table_cache->suuid = 'fake';
    }

    public function getIndexColumnName(bool $alterColumn = true): string
    {
        return 'column_' . $this->column_name;
    }

    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}
