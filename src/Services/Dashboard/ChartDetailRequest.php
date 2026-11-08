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
 *   k  the point's keys, {view column id: stored value} (ChartDetail::keys); the その他 point of
 *      表示件数 is {rest: 1} besides its series' key, if any (the points it leaves out are the
 *      box's to work out again: ChartItem::restDetail)
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
    /** @var array<int, array<int|string, string|int|float|bool|null>>|null the points kept, when the point is 表示件数's その他 */
    private $excluded;
    /** @var array{label: string, value: string}|null what その他 lists (上位5件以外（13件）) */
    private $rest_caption;

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
        // the その他 point of 表示件数: named by no keys of its own (the series of its cell at
        // most) — the points it leaves out are worked out again here, never sent (hundreds)
        $raw_keys = $data['k'] ?? null;
        $rest = is_array($raw_keys) && array_key_exists(ChartTopN::REST_KEY, $raw_keys);
        if ($rest) {
            unset($raw_keys[ChartTopN::REST_KEY]);
        }
        $keys = $rest && $raw_keys === [] ? [] : $detail->keys($raw_keys);
        if ($keys === null) {
            return;
        }
        // the chart filter as the chart was drawn by
        $item->settleFilters();
        if ($rest) {
            $rest_detail = $item->restDetail();
            $excluded = $rest_detail === null ? null : array_map([$detail, 'keys'], $rest_detail['excluded']);
            if ($excluded === null || in_array(null, $excluded, true)) {
                return;
            }
            $this->excluded = $excluded;
            $this->rest_caption = $rest_detail['caption'];
        }
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
                $this->detail->apply($query, $this->keys, $this->excluded);
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
        $rest = $this->rest_caption === null ? [] : [$this->rest_caption];
        foreach (array_merge($rest, $this->detail->captions($this->keys), $this->item->filterCaptions()) as $caption) {
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
