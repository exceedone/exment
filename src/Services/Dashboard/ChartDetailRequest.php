<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\System;

/**
 * A data list opened from a chart point (明細, ChartDetail): the `chart_detail` param of
 * data/{table}, a JSON object the dashboard sends on a double click —
 *
 *   b  the chart box's suuid
 *   k  the point's keys, {view column id: stored value} (ChartDetail::keys)
 *   q  the box's request params (dashboard.js boxQuery: df_*, bf_*, ct, ...), a query string
 *
 * The box is rebuilt from them as the chart was drawn, so the list holds the rows behind
 * the number on screen. Nothing of it is trusted: the box must be one of a dashboard the user
 * may view, its table one they may view, the list the table its 明細 lists, the keys group
 * columns of its view. Anything else lists no row and says so — never the whole table.
 */
final class ChartDetailRequest
{
    public const PARAM = 'chart_detail';

    /** @var DashboardBox|null */
    private $box;
    /** @var ChartItem|null */
    private $item;
    /** @var ChartDetail|null */
    private $detail;
    /** @var array<int|string, string|int|float|bool|null>|null */
    private $keys;
    /** @var array<string, mixed> the box's request params */
    private $params = [];

    /**
     * The 明細 the current request lists of $table, or null when it lists none (no param).
     * Read once per request: the controller and the grid's toolbar ask for it.
     */
    public static function current(CustomTable $table): ?self
    {
        $raw = request()->query(static::PARAM);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        return System::requestSession('chart_detail_request_' . $table->id . '_' . md5($raw), function () use ($table, $raw) {
            return new self($table, $raw);
        });
    }

    private function __construct(CustomTable $table, string $raw)
    {
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_string($data['b'] ?? null)) {
            return;
        }
        $box = DashboardBox::findByUuid($data['b']);
        // a box of a dashboard this user cannot view has none (Dashboard's showable scope)
        if (!$box instanceof DashboardBox || !isset($box->dashboard) || $box->dashboard_box_type != DashboardBoxType::CHART) {
            return;
        }
        $this->box = $box;
        if (is_string($data['q'] ?? null)) {
            parse_str($data['q'], $params);
            $this->params = array_filter($params, 'is_string', ARRAY_FILTER_USE_KEY);
        }
        $box_table = CustomTable::getEloquent(array_get($box->options ?? [], 'target_table_id'));
        if ($box_table === null || !$box_table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            return;
        }
        $item = new ChartItem($box, $this->params);
        $detail = $item->detail();
        if ($detail === null || $detail->table()->id != $table->id) {
            return;
        }
        $keys = $detail->keys($data['k'] ?? null);
        if ($keys === null) {
            return;
        }
        // the chart filter as the chart was drawn by
        $item->settleFilters();
        $this->item = $item;
        $this->detail = $detail;
        $this->keys = $keys;
    }

    /**
     * Whether the param names rows this user may see the list of.
     */
    public function valid(): bool
    {
        return $this->detail !== null;
    }

    /**
     * The data grid's filter (DefaultGrid's callback, in place of the view's): the rows
     * behind the point; none for an invalid param.
     */
    public function filter(): \Closure
    {
        return function ($query) {
            if ($this->detail !== null && $this->keys !== null) {
                $this->detail->apply($query, $this->keys);
            } else {
                $query->whereNotMatch();
            }
            return $query;
        };
    }

    /**
     * The box's title.
     */
    public function title(): string
    {
        return $this->box ? (string) $this->box->dashboard_box_view_name : '';
    }

    /**
     * The conditions the rows were picked by, human-readable: the point's group values, then
     * the filter bar's items and the chart filter that narrowed the chart — once each: a bar
     * item picking the very value of a group (教科: 算数, on the 教科 series) says nothing more.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function captions(): array
    {
        if ($this->detail === null || $this->item === null || $this->keys === null) {
            return [];
        }
        $out = [];
        foreach (array_merge($this->detail->captions($this->keys), $this->item->filterCaptions()) as $caption) {
            $out[$caption['label'] . "\0" . $caption['value']] = $caption;
        }
        return array_values($out);
    }

    /**
     * The dashboard the chart is on, with the filter bar's selection it was drawn under; the
     * dashboard home when the box is unknown.
     */
    public function backUrl(): string
    {
        $dashboard = $this->box ? $this->box->dashboard : null;
        if ($dashboard === null) {
            return admin_url('');
        }
        $selection = [];
        foreach ($this->params as $key => $value) {
            if (strpos($key, 'df_') === 0) {
                $selection[$key] = $value;
            }
        }
        return admin_url('') . '?' . FilterBarConfig::queryString(['dashboard' => (string) $dashboard->suuid] + $selection);
    }

    /**
     * The list without the 明細: every row of the table.
     */
    public function clearUrl(): string
    {
        $query = request()->query();
        unset($query[static::PARAM], $query['page']);
        return url()->current() . (empty($query) ? '' : '?' . http_build_query($query));
    }
}
