<?php

namespace Exceedone\Exment\Services\Dashboard;

/**
 * Runtime sort of a chart box (`cs` on the box request), picked on the toolbar's sort menu the
 * way a Power BI visual sorts: a FIELD of the chart plus a direction. Fields are the chart's own:
 * `x0`, `x1`, … = the X columns (one per group column of the aggregate view; the X item of
 * a list view), `y` = the measure. Applied after the data is fetched and aggregated, so it
 * holds for every aggregate and never touches the query. Unset = the order the view returns.
 */
final class ChartSort
{
    public const ASC = 'asc';
    public const DESC = 'desc';

    /** @var string `y` or `x{i}` */
    public $field;

    /** @var bool */
    public $desc;

    private function __construct(string $field, bool $desc)
    {
        $this->field = $field;
        $this->desc = $desc;
    }

    /**
     * "x0:desc" / "y:asc" → sort; null for unset / malformed (= the view's order).
     *
     * @param mixed $cs
     */
    public static function parse($cs): ?self
    {
        if (!is_string($cs) || !preg_match('/^(y|x\d{1,2}):(asc|desc)$/', $cs, $m)) {
            return null;
        }
        return new self($m[1], $m[2] === self::DESC);
    }

    /** Sorting by the measure (numeric), not by an X column (text). */
    public function byValue(): bool
    {
        return $this->field === 'y';
    }

    /** Index of the X column when sorting by one; -1 for the measure. */
    public function xIndex(): int
    {
        return $this->byValue() ? -1 : (int) substr($this->field, 1);
    }
}
