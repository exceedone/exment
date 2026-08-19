<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard\Support;

/**
 * Stand-in for Exceedone\Exment\Model\CustomColumn with exactly the surface the
 * dashboard services read: column_name / column_view_name / column_type / index_enabled
 * and getIndexColumnName(). No database, no model boot.
 */
class FakeCustomColumn
{
    public $column_name;
    public $column_view_name;
    public $column_type;
    public $index_enabled;
    public $suuid;

    public function __construct(string $name, string $type = 'select', bool $indexed = false, ?string $viewName = null)
    {
        $this->column_name = $name;
        $this->column_view_name = $viewName ?? strtoupper($name);
        $this->column_type = $type;
        $this->index_enabled = $indexed;
        $this->suuid = 'suuid_' . $name;
    }

    public function getIndexColumnName($alterColumn = true)
    {
        return 'column_' . $this->suuid;
    }
}
