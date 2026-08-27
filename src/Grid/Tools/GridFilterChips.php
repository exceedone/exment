<?php

namespace Exceedone\Exment\Grid\Tools;

use ExmentAdminCore\Admin\Grid\Tools\AbstractTool;
use Exceedone\Exment\Model\CustomTable;

/**
 * "You are looking at a filtered list" - shown on the grid itself.
 *
 * The filter panel is collapsed by default and, when
 * `custom_value_filter_ajax` is on, is not even in the page until it is
 * opened. So a filtered grid and an unfiltered one look identical, and
 * the only way to find out what is being filtered on is to open the
 * panel and read every field. That is also the reason "why is this
 * record missing" takes so long to answer.
 *
 * Each active condition is rendered as one chip carrying the column's
 * label, the value as the user would read it, and an X that links to the
 * same list without that condition. No JavaScript: the X is a plain
 * link, so it works under pjax like every other link in the grid.
 *
 * The pairs come from DefaultGrid, which captures them at the moment
 * each column item registers its filter - the request key is only known
 * there. Nothing is guessed from the query string on its own: a stray
 * parameter that no filter owns must not appear as a condition.
 */
class GridFilterChips extends AbstractTool
{
    /** @var CustomTable */
    protected $custom_table;

    /**
     * [['key' => request key, 'item' => column item], ...]
     *
     * @var array<int, array<string, mixed>>
     */
    protected $pairs;

    /**
     * @param CustomTable $custom_table
     * @param array<int, array<string, mixed>> $pairs
     */
    public function __construct(CustomTable $custom_table, array $pairs)
    {
        $this->custom_table = $custom_table;
        $this->pairs = $pairs;
    }

    /**
     * @return string
     */
    public function render()
    {
        $chips = [];
        foreach ($this->pairs as $pair) {
            $chip = $this->buildChip(array_get($pair, 'key'), array_get($pair, 'item'));
            if ($chip) {
                $chips[] = $chip;
            }
        }

        if (empty($chips)) {
            return '';
        }

        $title = e(exmtrans('common.grid_filter_chip_title'));
        $clearLabel = e(exmtrans('common.grid_filter_chip_clear'));
        $clearUrl = e($this->urlWithout(array_column($this->pairs, 'key')));
        $items = implode('', $chips);

        return <<<HTML
<div class="exm-filter-chips">
    <span class="exm-filter-chips-title"><i class="fa fa-filter"></i>&nbsp;{$title}</span>
    {$items}
    <a class="exm-filter-chip exm-filter-chip-clear" href="{$clearUrl}">{$clearLabel}</a>
</div>
HTML;
    }

    /**
     * One chip, or null when this filter carries no value.
     *
     * @param string|null $key
     * @param mixed $item
     * @return string|null
     */
    protected function buildChip($key, $item): ?string
    {
        if (is_nullorempty($key) || is_nullorempty($item)) {
            return null;
        }

        // A dotted key reaches the browser as `a[b]`, so the query
        // parameter - and everything below - is the part before the dot.
        $queryKey = explode('.', strval($key))[0];
        $value = request()->input($queryKey);
        $isNull = boolval(request()->input('isnull-' . $queryKey));

        if (!$isNull && $this->isEmptyValue($value)) {
            return null;
        }

        $label = e(strval($item->label()));
        $text = $isNull
            ? exmtrans('common.grid_filter_chip_null')
            : $this->valueText($item, $value);
        $text = e(mb_strimwidth(strval($text), 0, 60, '…'));
        $url = e($this->urlWithout([$queryKey]));
        $remove = e(exmtrans('common.grid_filter_chip_remove'));

        return <<<HTML
<a class="exm-filter-chip" href="{$url}" title="{$remove}">
    <span class="exm-filter-chip-key">{$label}</span>
    <span class="exm-filter-chip-value">{$text}</span>
    <i class="fa fa-times"></i>
</a>
HTML;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    protected function isEmptyValue($value): bool
    {
        if (is_array($value)) {
            return count(array_filter($value, function ($v) {
                return !is_nullorempty($v);
            })) === 0;
        }

        return is_nullorempty($value);
    }

    /**
     * The value as the user reads it, not as the database stores it.
     *
     * The column item is the only thing that knows how - a select maps
     * its value through the column's options, a select_table resolves to
     * the target record's label - and the way to ask it is to hand it a
     * value the way a record would. Anything that goes wrong on the way
     * falls back to the raw text: a chip with a code in it is still far
     * better than a silent filter.
     *
     * @param mixed $item
     * @param mixed $value
     * @return string
     */
    protected function valueText($item, $value): string
    {
        // Between filters (date / number ranges) arrive as start + end.
        if (is_array($value) && (array_key_exists('start', $value) || array_key_exists('end', $value))) {
            $start = strval(array_get($value, 'start'));
            $end = strval(array_get($value, 'end'));
            if ($start !== '' && $end !== '') {
                return "{$start} 〜 {$end}";
            }
            return $start !== '' ? "{$start} 〜" : "〜 {$end}";
        }

        $raw = is_array($value)
            ? implode(', ', array_filter($value, function ($v) {
                return !is_nullorempty($v);
            }))
            : strval($value);

        try {
            $custom_table = $item->getCustomTable();
            $name = $item->name();
            if ($custom_table && !is_nullorempty($name)) {
                $dummy = $custom_table->getValueModel();
                $dummy->setValue($name, $value);
                $item->setCustomValue($dummy);
                $text = $item->text();
                if (!is_nullorempty($text)) {
                    // text() may hand back an array for a multi-value column.
                    return is_array($text) ? implode(', ', $text) : strval($text);
                }
            }
        } catch (\Throwable $e) {
            // Deliberately swallowed - see the doc block.
        }

        return $raw;
    }

    /**
     * Does this query string still carry a condition of one of our filters?
     *
     * Only the keys the grid registered count. A leftover `_scope_` or a
     * sort parameter is not a condition, and treating it as one would
     * keep the filter flag alive forever.
     *
     * @param array<string, mixed> $query
     * @return bool
     */
    protected function hasAnyCondition(array $query): bool
    {
        foreach ($this->pairs as $pair) {
            $key = explode('.', strval(array_get($pair, 'key')))[0];
            if (!$this->isEmptyValue(array_get($query, $key))) {
                return true;
            }
            if (boolval(array_get($query, 'isnull-' . $key))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The current list URL without the given conditions.
     *
     * `page` goes too: the row that was on page 4 of the filtered list is
     * almost never on page 4 of the wider one.
     *
     * @param array<int, string|null> $keys
     * @return string
     */
    protected function urlWithout(array $keys): string
    {
        $query = request()->query();
        foreach ($keys as $key) {
            $key = explode('.', strval($key))[0];
            unset($query[$key]);
            unset($query['isnull-' . $key]);
        }
        unset($query['page']);

        // With no condition left there is nothing to execute, and leaving
        // the flag on would keep the filter panel open on a list that is
        // not filtered any more.
        if (!$this->hasAnyCondition($query)) {
            unset($query['execute_filter']);
        }

        $url = url()->current();
        return empty($query) ? $url : $url . '?' . http_build_query($query);
    }
}
