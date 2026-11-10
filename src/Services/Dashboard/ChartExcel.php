<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Enums\ChartType;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\Axis;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\ChartColor;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * A chart box as an Excel workbook (the box menu's Excel出力): one sheet with the title, the
 * filters the chart is drawn under, the numbers as drawn (ChartItem::excelData) and Excel's own
 * chart of them — or, for a heatmap, the cells colored by the box's thresholds through Excel's
 * conditional formatting. A type Excel has no chart for gets the table and a note.
 *
 * Layout: row 1 the title, 2 the dashboard / view / time, 3 the filters, 4 a note, 5 the table's
 * header, its rows below; the chart under the table. A box's target line (目標線) is a column of the
 * target drawn as a dashed line over the chart, as Excel's combo chart draws one. The 複合 type is
 * Excel's 組み合わせグラフ: the 値's column as bars, the 2つ目の値's as a line (or bars) on the
 * secondary axis — written into the chart's XML after PhpSpreadsheet, which has no secondary axis.
 */
class ChartExcel
{
    public const HEADER_ROW = 5;

    /** the most characters of a sheet name (Excel's limit) */
    public const SHEET_NAME_LENGTH = 31;

    /** Excel chart per type: [chart type, grouping, bar direction] */
    protected const CHARTS = [
        ChartType::BAR => [DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, DataSeries::DIRECTION_COL],
        ChartType::MBAR => [DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, DataSeries::DIRECTION_COL],
        ChartType::SBAR => [DataSeries::TYPE_BARCHART, DataSeries::GROUPING_STACKED, DataSeries::DIRECTION_COL],
        ChartType::SBAR100 => [DataSeries::TYPE_BARCHART, DataSeries::GROUPING_PERCENT_STACKED, DataSeries::DIRECTION_COL],
        ChartType::HBAR => [DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, DataSeries::DIRECTION_BAR],
        // the 値's bars; the 2つ目の値 a chart group of its own (addChart)
        ChartType::COMBO => [DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, DataSeries::DIRECTION_COL],
        ChartType::SHBAR100 => [DataSeries::TYPE_BARCHART, DataSeries::GROUPING_PERCENT_STACKED, DataSeries::DIRECTION_BAR],
        ChartType::LINE => [DataSeries::TYPE_LINECHART, DataSeries::GROUPING_STANDARD, null],
        ChartType::MLINE => [DataSeries::TYPE_LINECHART, DataSeries::GROUPING_STANDARD, null],
        // a scatter of categories (Exment's X axis lists the labels): points without a line
        ChartType::SCATTER => [DataSeries::TYPE_LINECHART, DataSeries::GROUPING_STANDARD, null],
        ChartType::AREA => [DataSeries::TYPE_AREACHART, DataSeries::GROUPING_STANDARD, null],
        ChartType::SAREA => [DataSeries::TYPE_AREACHART, DataSeries::GROUPING_STACKED, null],
        ChartType::PIE => [DataSeries::TYPE_PIECHART, null, null],
        ChartType::DOUGHNUT => [DataSeries::TYPE_DOUGHNUTCHART, null, null],
        ChartType::RADAR => [DataSeries::TYPE_RADARCHART, null, null],
    ];

    /** the types whose series are stacked: a row's 合計 is the height of its stack */
    protected const STACKED = [ChartType::SBAR, ChartType::SBAR100, ChartType::SHBAR100, ChartType::SAREA];

    protected const HEADER_FILL = '4472C4';
    protected const BORDER = 'BFBFBF';
    protected const MUTED = '6B6B6B';
    protected const DARK_INK = '2B2B3A';

    /** an area's fill, % transparent (the chart's opacity 0.25–0.3) */
    protected const AREA_TRANSPARENCY = 70;

    /** the target line's width, pt (the chart's 2px), and its dashes (Excel's 破線) */
    protected const TARGET_WIDTH = 1.5;
    protected const TARGET_DASH = 'dash';

    /** the chart XML's namespace (the secondary axis is written into it) */
    protected const CHART_NS = 'http://schemas.openxmlformats.org/drawingml/2006/chart';

    /** the ids of the secondary axes: none PhpSpreadsheet gives its own */
    protected const SECONDARY_CATEGORY_AXIS = '510438656';
    protected const SECONDARY_VALUE_AXIS = '510444544';

    /** an axis whose numbers reach this is numbered in millions (#,##0,,"百万"), as Excel's sample of the 複合 type */
    protected const MILLIONS = 10000000;

    /** the most points the 複合 type's line is labelled on by itself (more read as a blur; 値ラベル labels any) */
    protected const LINE_LABELS_MAX = 24;

    /** @var array<string, mixed> ChartItem::excelData() */
    protected $data;

    /** @var \Carbon\Carbon */
    protected $now;

    /**
     * @param array<string, mixed> $data  ChartItem::excelData()
     * @param \Carbon\Carbon|null $now  the time written as the output date
     */
    public function __construct(array $data, $now = null)
    {
        $this->data = $data;
        $this->now = $now ?? \Carbon\Carbon::now();
    }

    /**
     * The download's name: the box title and the date (売上_20261107.xlsx).
     */
    public function fileName(): string
    {
        $title = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f\x7f]+/u', '_', (string) $this->data['title']) ?? '');
        return ($title === '' ? 'chart' : $title) . '_' . $this->now->format('Ymd') . '.xlsx';
    }

    /**
     * The sheet's name: the box title without the characters a sheet name cannot hold, cut to 31.
     */
    public static function sheetName(string $title): string
    {
        $name = preg_replace('/[\\\\\/:*?\[\]\x00-\x1f\x7f]+/u', ' ', $title) ?? '';
        $name = trim(mb_substr(trim($name, " '"), 0, static::SHEET_NAME_LENGTH), " '");
        return $name === '' ? 'Chart' : $name;
    }

    /**
     * Write the workbook to a file.
     */
    public function save(string $path): void
    {
        $writer = new Xlsx($this->spreadsheet());
        $writer->setIncludeCharts(true);
        $writer->save($path);
        $this->writeChartXml($path);
    }

    public function spreadsheet(): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getDefaultStyle()->getFont()->setName('Yu Gothic')->setSize(10);
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(static::sheetName((string) $this->data['title']));

        $this->writeHeading($sheet);
        $type = (string) $this->data['type'];
        if ($type === ChartType::HEATMAP) {
            $this->writeHeatmap($sheet);
        } else {
            $table = $this->writeTable($sheet);
            if (isset(static::CHARTS[$type])) {
                $this->addChart($sheet, $table);
            } else {
                $this->note($sheet, exmtrans('dashboard.chart_excel.table_only', exmtrans('chart.chart_type_options.' . $type)));
            }
        }
        $sheet->setSelectedCell('A1');
        return $book;
    }

    /**
     * Rows 1–3: the title; the dashboard, the view, the time; the filters the chart is drawn under.
     */
    protected function writeHeading(Worksheet $sheet): void
    {
        $this->text($sheet, 'A1', (string) $this->data['title']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $meta = [exmtrans('dashboard.chart_excel.printed', $this->now->format('Y/m/d H:i'))];
        if ($this->data['dashboard'] !== '') {
            $meta[] = exmtrans('dashboard.chart_excel.dashboard', $this->data['dashboard']);
        }
        $meta[] = exmtrans('dashboard.chart_excel.view', $this->data['view']);
        $this->text($sheet, 'A2', implode('　', $meta));

        $captions = array_map(function ($caption) {
            return $caption['label'] . ': ' . $caption['value'];
        }, (array) $this->data['captions']);
        $this->text($sheet, 'A3', exmtrans('dashboard.chart_excel.filters', count($captions) ? implode(' ／ ', $captions) : exmtrans('dashboard.chart_excel.no_filter')));
        $sheet->getStyle('A2:A3')->getFont()->setSize(9)->getColor()->setRGB(static::MUTED);
    }

    protected function note(Worksheet $sheet, string $note): void
    {
        $this->text($sheet, 'A4', $note);
        $sheet->getStyle('A4')->getFont()->setSize(9)->getColor()->setRGB(static::MUTED);
    }

    /**
     * The table of a chart: a row per category (column A), a column per series, as drawn; then a
     * 合計 per row where the series stack and add up, a 割合 per slice of a pie that adds up, and
     * the target of the box's target line on every row, under its name.
     *
     * @return array{last_row: int, last_col: int, series: int, target_col: int|null}  the table's extent
     */
    protected function writeTable(Worksheet $sheet): array
    {
        $categories = $this->data['categories'];
        $series = $this->data['series'];
        $matrix = $this->data['matrix'];
        $top = static::HEADER_ROW + 1;
        $last_row = static::HEADER_ROW + count($categories);

        $this->text($sheet, 'A' . static::HEADER_ROW, (string) $this->data['axisx']);
        foreach ($series as $s => $name) {
            $this->text($sheet, [$s + 2, static::HEADER_ROW], (string) $name);
        }
        foreach ($categories as $x => $category) {
            $this->text($sheet, [1, $top + $x], (string) $category);
            foreach ($series as $s => $name) {
                $this->number($sheet, [$s + 2, $top + $x], $matrix[$s][$x] ?? null);
            }
        }
        $last_col = count($series) + 1;
        $values = Coordinate::stringFromColumnIndex(2) . $top . ':' . Coordinate::stringFromColumnIndex($last_col) . $last_row;
        $sheet->getStyle($values)->getNumberFormat()->setFormatCode($this->numberFormat());
        // the 複合 type: each value in its own units (394件 / ¥1,200)
        foreach ($this->comboUnits() as $s => $unit) {
            $col = Coordinate::stringFromColumnIndex($s + 2);
            $sheet->getStyle("{$col}{$top}:{$col}{$last_row}")->getNumberFormat()->setFormatCode(ChartSecondValue::excelFormat($unit, array_values((array) ($matrix[$s] ?? []))));
        }

        $type = (string) $this->data['type'];
        $adds_up = !empty($this->data['adds_up']) && count($categories) > 0;
        if ($adds_up && in_array($type, static::STACKED, true)) {
            $col = Coordinate::stringFromColumnIndex(++$last_col);
            $to = Coordinate::stringFromColumnIndex($last_col - 1);
            $this->text($sheet, $col . static::HEADER_ROW, exmtrans('dashboard.chart_excel.total'));
            for ($row = $top; $row <= $last_row; $row++) {
                $sheet->setCellValue($col . $row, "=SUM(B{$row}:{$to}{$row})");
            }
            $sheet->getStyle("{$col}{$top}:{$col}{$last_row}")->getNumberFormat()->setFormatCode($this->numberFormat());
            $sheet->getStyle("{$col}{$top}:{$col}{$last_row}")->getFont()->setBold(true);
        } elseif ($adds_up && in_array($type, [ChartType::PIE, ChartType::DOUGHNUT], true)) {
            $col = Coordinate::stringFromColumnIndex(++$last_col);
            $this->text($sheet, $col . static::HEADER_ROW, exmtrans('dashboard.chart_excel.share'));
            for ($row = $top; $row <= $last_row; $row++) {
                $sheet->setCellValue($col . $row, "=IFERROR(B{$row}/SUM(\$B\${$top}:\$B\${$last_row}),\"\")");
            }
            $sheet->getStyle("{$col}{$top}:{$col}{$last_row}")->getNumberFormat()->setFormatCode('0.0%');
        }

        $target = $this->data['target'] ?? null;
        $target_col = null;
        if (is_array($target) && count($categories) > 0) {
            $target_col = ++$last_col;
            $col = Coordinate::stringFromColumnIndex($target_col);
            $this->text($sheet, $col . static::HEADER_ROW, (string) $target['name']);
            for ($row = $top; $row <= $last_row; $row++) {
                $sheet->setCellValueExplicit($col . $row, $target['value'], DataType::TYPE_NUMERIC);
            }
            $sheet->getStyle("{$col}{$top}:{$col}{$last_row}")->getNumberFormat()->setFormatCode(static::formatOf([$target['value']]));
        }

        $this->frame($sheet, $last_col, $last_row);
        return ['last_row' => $last_row, 'last_col' => $last_col, 'series' => count($series), 'target_col' => $target_col];
    }

    /**
     * A heatmap as the chart's grid: a row per series, a column per category, each cell colored by
     * the box's thresholds through conditional formatting (the first rule a value meets, as on
     * the chart; an empty cell none). Without thresholds the cells stay plain.
     */
    protected function writeHeatmap(Worksheet $sheet): void
    {
        $categories = $this->data['categories'];
        $series = $this->data['series'];
        $matrix = $this->data['matrix'];
        $top = static::HEADER_ROW + 1;
        $last_row = static::HEADER_ROW + count($series);
        $last_col = count($categories) + 1;

        $this->text($sheet, 'A' . static::HEADER_ROW, (string) ($this->data['series_label'] ?? ''));
        foreach ($categories as $x => $category) {
            $this->text($sheet, [$x + 2, static::HEADER_ROW], (string) $category);
        }
        foreach ($series as $s => $name) {
            $this->text($sheet, [1, $top + $s], (string) $name);
            foreach ($categories as $x => $category) {
                $this->number($sheet, [$x + 2, $top + $s], $matrix[$s][$x] ?? null);
            }
        }
        $this->frame($sheet, $last_col, $last_row);
        if ($last_col < 2 || $last_row < $top) {
            return;
        }
        $range = 'B' . $top . ':' . Coordinate::stringFromColumnIndex($last_col) . $last_row;
        $sheet->getStyle($range)->getNumberFormat()->setFormatCode($this->numberFormat());
        $sheet->getStyle($range)->getAlignment()->setHorizontal('center');
        $sheet->freezePane('B' . $top);

        /** @var ChartThresholds|null $thresholds */
        $thresholds = $this->data['thresholds'] ?? null;
        if ($thresholds === null || $thresholds->isEmpty()) {
            $this->note($sheet, exmtrans('dashboard.chart_excel.heatmap_plain'));
            return;
        }
        $labels = [];
        foreach ($thresholds->pieces() as $piece) {
            if ($piece['rule'] !== ChartThresholds::NONE) {
                $labels[] = $piece['label'];
            }
        }
        $this->note($sheet, exmtrans('dashboard.chart_excel.heatmap_rules', implode('、', $labels)));

        $cell = 'B' . $top;
        $conditions = [];
        foreach ($thresholds->rules() as $rule) {
            $conditions[] = $this->rule("AND(ISNUMBER({$cell})," . static::formula($rule, $cell) . ')', $rule['color']);
        }
        // a value no rule takes: the chart's plain cell
        $conditions[] = $this->rule("ISNUMBER({$cell})", ChartThresholds::NONE_COLOR);
        $sheet->getStyle($range)->setConditionalStyles($conditions);
    }

    /**
     * A rule's condition on $cell as an Excel formula (範囲: A <= v < B).
     *
     * @param array{condition: string, value: int|float, value2?: int|float} $rule
     */
    protected static function formula(array $rule, string $cell): string
    {
        $a = static::literal($rule['value']);
        switch ($rule['condition']) {
            case ChartThresholds::LT:
                return "{$cell}<{$a}";
            case ChartThresholds::LTE:
                return "{$cell}<={$a}";
            case ChartThresholds::GTE:
                return "{$cell}>={$a}";
            case ChartThresholds::GT:
                return "{$cell}>{$a}";
            default: // RANGE
                return "{$cell}>={$a},{$cell}<" . static::literal($rule['value2'] ?? $rule['value']);
        }
    }

    /**
     * A number as a formula writes it: no exponent, no thousands separator, no trailing zero.
     *
     * @param int|float $n
     */
    protected static function literal($n): string
    {
        $text = rtrim(rtrim(sprintf('%.10F', $n), '0'), '.');
        return $text === '-0' ? '0' : $text;
    }

    /**
     * A conditional format filling the cell with $color (#rrggbb), its text dark or white by the
     * fill's brightness as on the chart; it stops the rules after it, the first one met wins.
     */
    protected function rule(string $formula, string $color): Conditional
    {
        $rgb = static::rgb($color) ?? 'FFFFFF';
        $conditional = new Conditional();
        $conditional->setConditionType(Conditional::CONDITION_EXPRESSION)->addCondition($formula)->setStopIfTrue(true);
        $fill = $conditional->getStyle()->getFill();
        $fill->setFillType(Fill::FILL_SOLID);
        $fill->getStartColor()->setARGB('FF' . $rgb);
        $fill->getEndColor()->setARGB('FF' . $rgb);
        $conditional->getStyle()->getFont()->getColor()->setARGB('FF' . static::inkOn($rgb));
        return $conditional;
    }

    /**
     * The text color readable on a fill: white on a dark one (the chart's inkOn).
     */
    protected static function inkOn(string $rgb): string
    {
        $n = hexdec($rgb);
        return (0.299 * ($n >> 16 & 255) + 0.587 * ($n >> 8 & 255) + 0.114 * ($n & 255)) < 140 ? 'FFFFFF' : static::DARK_INK;
    }

    /**
     * Excel's chart of the table, under it, in the box's colors; the target column a dashed line
     * over it (a combo chart) — but on a horizontal bar, across which Excel draws no line: there
     * the column only, and a note.
     *
     * @param array{last_row: int, last_col: int, series: int, target_col: int|null} $table
     */
    protected function addChart(Worksheet $sheet, array $table): void
    {
        $type = (string) $this->data['type'];
        [$chart_type, $grouping, $direction] = static::CHARTS[$type];
        $count = count($this->data['categories']);
        $quoted = "'" . str_replace("'", "''", $sheet->getTitle()) . "'";
        $top = static::HEADER_ROW + 1;
        $last = max($top, $table['last_row']);

        $per_point = $this->data['point_colors'] !== null && in_array($chart_type, [DataSeries::TYPE_BARCHART, DataSeries::TYPE_PIECHART, DataSeries::TYPE_DOUGHNUTCHART], true);
        $second = $this->comboSecond();
        $labels = [];
        $values = [];
        for ($s = 0; $s < $table['series']; $s++) {
            // the 複合 type's 2つ目の値: a line (or bars) in its own color, not the 値's bars
            $own = $second !== null && $s === 1;
            $as = $own ? ($second['type'] === ChartSecondValue::BAR ? DataSeries::TYPE_BARCHART : DataSeries::TYPE_LINECHART) : $chart_type;
            $col = Coordinate::stringFromColumnIndex($s + 2);
            $label = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$quoted}!\${$col}\$" . static::HEADER_ROW, null, 1);
            $value = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "{$quoted}!\${$col}\${$top}:\${$col}\${$last}", null, $count);
            $color = static::rgb($this->data['colors'][$s % max(1, count($this->data['colors']))] ?? null);
            if ($per_point && !$own) {
                $value->setFillColor(array_map(function ($c) {
                    return static::rgb($c) ?? '';
                }, array_values($this->data['point_colors'])));
            } elseif ($color !== null) {
                if (in_array($as, [DataSeries::TYPE_LINECHART, DataSeries::TYPE_RADARCHART], true)) {
                    // a line in the color, its points too
                    $value->setLineColorProperties($color, null, ChartColor::EXCEL_COLOR_TYPE_RGB);
                    $value->getMarkerFillColor()->setColorProperties($color);
                    $value->getMarkerBorderColor()->setColorProperties($color);
                } elseif ($chart_type === DataSeries::TYPE_AREACHART) {
                    // a translucent area under its line, as the chart draws it
                    $value->setFillColor(new ChartColor($color, static::AREA_TRANSPARENCY, ChartColor::EXCEL_COLOR_TYPE_RGB));
                    $value->setLineColorProperties($color, null, ChartColor::EXCEL_COLOR_TYPE_RGB);
                } else {
                    $value->setFillColor($color);
                }
            }
            if (in_array($as, [DataSeries::TYPE_LINECHART, DataSeries::TYPE_RADARCHART], true)) {
                $value->setPointMarker('circle');
                $value->setPointSize($type === ChartType::SCATTER ? 9 : 5);
                $value->setScatterLines($type !== ChartType::SCATTER);
            }
            $labels[] = $label;
            $values[] = $value;
        }
        $categories = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$quoted}!\$A\${$top}:\$A\${$last}", null, $count)];
        $plots = [];
        if ($second !== null && count($values) > 1) {
            $plots = $this->comboSeries($second, $labels, $categories, $values);
            $series = array_shift($plots);
        } else {
            $series = new DataSeries($chart_type, $grouping, range(0, count($values) - 1), $labels, $categories, $values, $direction, false, $chart_type === DataSeries::TYPE_RADARCHART ? DataSeries::STYLE_MARKER : null);
            // the multi-series lines are curves on the chart, the single one is straight
            $series->setSmoothLine($type === ChartType::MLINE);
        }

        $layout = new Layout();
        $circular = in_array($chart_type, [DataSeries::TYPE_PIECHART, DataSeries::TYPE_DOUGHNUTCHART], true);
        if ($type === ChartType::DOUGHNUT) {
            // the ring names its slices' shares as the chart does; their names, where no legend does
            $layout->setShowPercent(true);
            $layout->setShowCatName(empty($this->data['legend']));
        } elseif (!empty($this->data['labels'])) {
            $layout->setShowVal(true);
        }

        $legend = null;
        if ($circular) {
            $legend = empty($this->data['legend']) ? null : new Legend(Legend::POSITION_RIGHT, null, false);
        } elseif ((ChartType::isMulti($type) && !empty($this->data['legend'])) || $second !== null) {
            // (the 複合 type's two values are told apart by it)
            $legend = new Legend(Legend::POSITION_BOTTOM, null, false);
        }

        $category_axis = null;
        $value_axis = null;
        $axis_titles = [null, null];
        if (!$circular && $chart_type !== DataSeries::TYPE_RADARCHART) {
            $axis_titles = [static::axisTitle((string) $this->data['axisx']), static::axisTitle((string) $this->data['axisy'])];
        }
        if ($direction === DataSeries::DIRECTION_BAR) {
            // the first category on top, as the chart lists it (Excel draws it at the bottom), the
            // values' axis staying under the bars
            $category_axis = new Axis();
            $category_axis->setAxisOptionsProperties(Axis::AXIS_LABELS_NEXT_TO, null, null, Axis::ORIENTATION_REVERSED);
            $value_axis = new Axis();
            $value_axis->setAxisOptionsProperties(Axis::AXIS_LABELS_NEXT_TO, null, Axis::HORIZONTAL_CROSSES_MAXIMUM);
        }
        if ($second !== null) {
            // the left axis numbered in the 値's units — and named after it when the 2つ目の値 has an
            // axis of its own, else it is the axis of both
            $value_axis = new Axis();
            $value_axis->setAxisNumberProperties($this->axisFormat(0));
            if (empty($second['right'])) {
                $axis_titles[1] = null;
            }
        }

        $plots = array_merge([$series], $plots);
        if ($table['target_col'] !== null) {
            if ($direction === DataSeries::DIRECTION_BAR) {
                $this->note($sheet, exmtrans('dashboard.chart_excel.target_column_only', (string) $this->data['target']['name']));
            } else {
                $plots[] = $this->targetSeries($quoted, $table['target_col'], $categories, $count);
            }
        }

        $chart = new Chart(
            'chart1',
            new Title((string) $this->data['title']),
            $legend,
            new PlotArea($layout, $plots),
            true,
            DataSeries::EMPTY_AS_GAP,
            $axis_titles[0],
            $axis_titles[1],
            $category_axis,
            $value_axis
        );
        // under the table: as wide as 8 columns or the table; a horizontal bar as tall as its bars need
        $height = $direction === DataSeries::DIRECTION_BAR ? max(20, (int) ceil($count * ($table['series'] > 1 ? 1.6 : 1.3)) + 8) : 20;
        $from = $table['last_row'] + 2;
        $chart->setTopLeftPosition('A' . $from);
        $chart->setBottomRightPosition(Coordinate::stringFromColumnIndex(max(8, $table['last_col'])) . ($from + $height));
        $sheet->addChart($chart);
    }

    /**
     * The target column as a line of the chart: dashed, in the target's color, no markers.
     *
     * @param string $quoted  the sheet's name, quoted for a reference
     * @param array<int, DataSeriesValues> $categories
     */
    protected function targetSeries(string $quoted, int $target_col, array $categories, int $count): DataSeries
    {
        $col = Coordinate::stringFromColumnIndex($target_col);
        $top = static::HEADER_ROW + 1;
        $last = max($top, static::HEADER_ROW + $count);
        $label = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$quoted}!\${$col}\$" . static::HEADER_ROW, null, 1);
        $value = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "{$quoted}!\${$col}\${$top}:\${$col}\${$last}", null, $count);
        $color = static::rgb($this->data['target']['color'] ?? null);
        if ($color !== null) {
            $value->setLineColorProperties($color, null, ChartColor::EXCEL_COLOR_TYPE_RGB);
        }
        $value->setLineStyleProperties(static::TARGET_WIDTH, '', static::TARGET_DASH);
        $value->setPointMarker('none');
        return new DataSeries(DataSeries::TYPE_LINECHART, DataSeries::GROUPING_STANDARD, [0], [$label], $categories, [$value]);
    }

    /**
     * The 複合 type's 2つ目の値 (ChartItem::excelData `second`): {type, right, units}; null on another type.
     *
     * @return array{type: string, right: bool, units: array<int, array{prefix: string, suffix: string, digits: int|null}>}|null
     */
    protected function comboSecond(): ?array
    {
        $second = $this->data['second'] ?? null;
        if ($this->data['type'] !== ChartType::COMBO || !is_array($second) || count($this->data['series']) < 2) {
            return null;
        }
        /** @var array{type: string, right: bool, units: array<int, array{prefix: string, suffix: string, digits: int|null}>} $second */
        return $second;
    }

    /**
     * The units of the 複合 type's two values, per series; [] on another type.
     *
     * @return array<int, array{prefix: string, suffix: string, digits: int|null}>
     */
    protected function comboUnits(): array
    {
        $second = $this->comboSecond();
        return $second === null ? [] : array_values($second['units']);
    }

    /**
     * Whether the 2つ目の値 has the secondary axis (第2軸).
     */
    protected function hasSecondaryAxis(): bool
    {
        $second = $this->comboSecond();
        return $second !== null && !empty($second['right']);
    }

    /**
     * The chart groups of the 複合 type: the 値's bars, and the 2つ目の値 — a line, or bars — as a
     * group of its own (the one writeSecondaryAxis moves to the secondary axis); bars of both on one
     * axis are one group, side by side. A line is labelled with its values, as on the chart — up to
     * LINE_LABELS_MAX points, where Excel does not hide the labels that overlap.
     *
     * @param array{type: string, right: bool, units: array<int, array{prefix: string, suffix: string, digits: int|null}>} $second
     * @param array<int, DataSeriesValues> $labels
     * @param array<int, DataSeriesValues> $categories
     * @param array<int, DataSeriesValues> $values
     * @return array<int, DataSeries>
     */
    protected function comboSeries(array $second, array $labels, array $categories, array $values): array
    {
        $bars = $second['type'] === ChartSecondValue::BAR;
        if ($bars && empty($second['right'])) {
            return [new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, [0, 1], $labels, $categories, $values, DataSeries::DIRECTION_COL)];
        }
        if (!$bars && (count($this->data['categories']) <= static::LINE_LABELS_MAX || !empty($this->data['labels']))) {
            $layout = new Layout();
            $layout->setShowVal(true);
            $layout->setNumFmtCode(ChartSecondValue::excelFormat($second['units'][1], array_values((array) ($this->data['matrix'][1] ?? []))));
            $layout->setNumFmtLinked(false);
            $layout->setDLblPos('t');
            $values[1]->setLabelLayout($layout);
        }
        return [
            new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, [0], [$labels[0]], $categories, [$values[0]], DataSeries::DIRECTION_COL),
            $bars
                ? new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, [0], [$labels[1]], $categories, [$values[1]], DataSeries::DIRECTION_COL)
                : new DataSeries(DataSeries::TYPE_LINECHART, DataSeries::GROUPING_STANDARD, [0], [$labels[1]], $categories, [$values[1]]),
        ];
    }

    /**
     * The number format of the 複合 type's axis of series $s (0: the left one, 1: the right one):
     * its value's units, in millions once its numbers reach MILLIONS (the left one holds the 2つ目の
     * 値 too when it has no axis of its own, and the target).
     */
    protected function axisFormat(int $s): string
    {
        $units = $this->comboUnits();
        $numbers = array_values((array) ($this->data['matrix'][$s] ?? []));
        if ($s === 0 && !$this->hasSecondaryAxis()) {
            $numbers = array_merge($numbers, array_values((array) ($this->data['matrix'][1] ?? [])));
        }
        if ($s === 0 && is_array($this->data['target'] ?? null)) {
            $numbers[] = $this->data['target']['value'];
        }
        $top = 0;
        foreach ($numbers as $n) {
            if (is_numeric($n)) {
                $top = max($top, abs((float) $n));
            }
        }
        return ChartSecondValue::excelFormat($units[$s], $numbers, $top >= static::MILLIONS);
    }

    /**
     * The chart's XML made what Excel reads (fixChart) — and, on the 複合 type, the 2つ目の値 on the
     * secondary axis as Excel writes a 組み合わせグラフ (secondaryAxis; PhpSpreadsheet has none).
     * Rewritten only when changed.
     */
    protected function writeChartXml(string $path): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $xml = preg_match('#\Axl/charts/chart\d+\.xml\z#', $name) ? $zip->getFromName($name) : false;
            if (!is_string($xml) || $xml === '') {
                continue;
            }
            $dom = new DOMDocument();
            $dom->loadXML($xml);
            $changed = static::fixChart($dom);
            if ($this->hasSecondaryAxis()) {
                $changed = $this->secondaryAxis($dom) || $changed;
            }
            if ($changed) {
                $zip->addFromString($name, (string) $dom->saveXML());
            }
        }
        $zip->close();
    }

    /**
     * PhpSpreadsheet writes the chart groups of one type — a line and the target's line, the 複合
     * type's two values as bars — as one element, but heads the series with the type's settings
     * (c:grouping …) once per group: Excel refuses such a file. They are kept once, before the series.
     */
    protected static function fixChart(DOMDocument $dom): bool
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('c', static::CHART_NS);
        $changed = false;
        foreach (static::elements($xpath, '//c:plotArea/c:barChart|//c:plotArea/c:lineChart|//c:plotArea/c:areaChart') as $group) {
            $seen = [];
            foreach (static::elements($xpath, 'c:barDir|c:grouping|c:varyColors', $group) as $setting) {
                if (isset($seen[$setting->localName])) {
                    $group->removeChild($setting);
                    $changed = true;
                }
                $seen[$setting->localName] = true;
            }
        }
        return $changed;
    }

    /**
     * The 2つ目の値 on the secondary axis, as Excel writes a 組み合わせグラフ: its chart group crosses a
     * 2nd category axis (hidden) and a 2nd value axis on the right — named after it, numbered in its
     * units, without gridlines — copied from the first ones. false when the chart is not the one
     * expected (left as written).
     */
    protected function secondaryAxis(DOMDocument $dom): bool
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('c', static::CHART_NS);
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $plot = static::element($xpath, '//c:plotArea');
        $cat = $plot === null ? null : static::element($xpath, 'c:catAx', $plot);
        $val = $plot === null ? null : static::element($xpath, 'c:valAx', $plot);
        $group = $plot === null ? null : $this->secondGroup($xpath, $plot);
        $ids = $group === null ? [] : static::elements($xpath, 'c:axId', $group);
        if ($plot === null || $cat === null || $val === null || count($ids) !== 2) {
            return false;
        }
        $ids[0]->setAttribute('val', static::SECONDARY_CATEGORY_AXIS);
        $ids[1]->setAttribute('val', static::SECONDARY_VALUE_AXIS);

        // the value axis on the right, crossing at the categories' end, named after the 2つ目の値
        $val2 = $val->cloneNode(true);
        $cat2 = $cat->cloneNode(true);
        if (!$val2 instanceof DOMElement || !$cat2 instanceof DOMElement) {
            return false;
        }
        static::setVal($xpath, 'c:axId', $val2, static::SECONDARY_VALUE_AXIS);
        static::setVal($xpath, 'c:crossAx', $val2, static::SECONDARY_CATEGORY_AXIS);
        static::setVal($xpath, 'c:axPos', $val2, 'r');
        foreach (static::elements($xpath, 'c:majorGridlines|c:minorGridlines|c:crossesAt', $val2) as $node) {
            $val2->removeChild($node);
        }
        if (static::element($xpath, 'c:crosses', $val2) === null) {
            $cross = static::element($xpath, 'c:crossAx', $val2);
            $val2->insertBefore($dom->createElementNS(static::CHART_NS, 'c:crosses'), $cross === null ? null : $cross->nextSibling);
        }
        static::setVal($xpath, 'c:crosses', $val2, 'max');
        foreach (static::elements($xpath, 'c:title//a:t', $val2) as $i => $text) {
            $text->textContent = $i === 0 ? (string) ($this->data['series'][1] ?? '') : '';
        }
        $format = static::element($xpath, 'c:numFmt', $val2);
        if ($format !== null) {
            $format->setAttribute('formatCode', $this->axisFormat(1));
            $format->setAttribute('sourceLinked', '0');
        }

        // its category axis: the first one's, hidden
        static::setVal($xpath, 'c:axId', $cat2, static::SECONDARY_CATEGORY_AXIS);
        static::setVal($xpath, 'c:crossAx', $cat2, static::SECONDARY_VALUE_AXIS);
        static::setVal($xpath, 'c:delete', $cat2, '1');
        foreach (static::elements($xpath, 'c:title', $cat2) as $node) {
            $cat2->removeChild($node);
        }

        // after the first axes, as Excel lists them
        $last = static::isAfter($val, $cat) ? $val : $cat;
        $plot->insertBefore($cat2, $last->nextSibling);
        $plot->insertBefore($val2, $cat2);
        return true;
    }

    /**
     * The chart group of the 2つ目の値 — the series of the table's column C — alone: PhpSpreadsheet
     * writes the groups of one type as one element, the 2つ目の値's bars with the 値's, its line with
     * the target's, which stay on the left axis: a group shared is split, the series in one of its own
     * right after it. null without the series.
     */
    protected function secondGroup(DOMXPath $xpath, DOMElement $plot): ?DOMElement
    {
        $column = '$' . Coordinate::stringFromColumnIndex(3) . '$';
        $isSecond = function (DOMElement $ser) use ($xpath, $column) {
            $ref = static::element($xpath, 'c:val/c:numRef/c:f', $ser);
            $text = $ref === null ? '' : (string) $ref->textContent;
            $at = strrpos($text, '!');
            return strpos($at === false ? $text : substr($text, $at + 1), $column) === 0;
        };
        $series = null;
        foreach (static::elements($xpath, 'c:barChart/c:ser|c:lineChart/c:ser', $plot) as $ser) {
            if ($isSecond($ser)) {
                $series = $ser;
                break;
            }
        }
        $group = $series === null ? null : $series->parentNode;
        if ($series === null || !$group instanceof DOMElement) {
            return null;
        }
        if (count(static::elements($xpath, 'c:ser', $group)) === 1) {
            return $group;
        }
        $own = $group->cloneNode(true);
        if (!$own instanceof DOMElement) {
            return null;
        }
        $group->removeChild($series);
        foreach (static::elements($xpath, 'c:ser', $own) as $ser) {
            if (!$isSecond($ser)) {
                $own->removeChild($ser);
            }
        }
        $plot->insertBefore($own, $group->nextSibling);
        return $own;
    }

    /**
     * The elements $path finds (under $node).
     *
     * @return DOMElement[]
     */
    protected static function elements(DOMXPath $xpath, string $path, ?DOMNode $node = null): array
    {
        $found = $xpath->query($path, $node);
        $elements = [];
        foreach ($found === false ? [] : $found as $item) {
            if ($item instanceof DOMElement) {
                $elements[] = $item;
            }
        }
        return $elements;
    }

    /**
     * The first element $path finds (under $node).
     */
    protected static function element(DOMXPath $xpath, string $path, ?DOMNode $node = null): ?DOMElement
    {
        return static::elements($xpath, $path, $node)[0] ?? null;
    }

    /**
     * Sets the `val` of the element $path finds under $node.
     */
    protected static function setVal(DOMXPath $xpath, string $path, DOMElement $node, string $value): void
    {
        $element = static::element($xpath, $path, $node);
        if ($element !== null) {
            $element->setAttribute('val', $value);
        }
    }

    /**
     * Whether $node comes after $other among their parent's children.
     */
    protected static function isAfter(DOMElement $node, DOMElement $other): bool
    {
        for ($n = $other->nextSibling; $n !== null; $n = $n->nextSibling) {
            if ($n === $node) {
                return true;
            }
        }
        return false;
    }

    /**
     * An axis' name, small as the chart's (Excel's default is the size of a heading).
     */
    protected static function axisTitle(string $text): Title
    {
        $rich = new RichText();
        $font = $rich->createTextRun($text)->getFont();
        if ($font !== null) {
            $font->setSize(9)->setBold(false);
        }
        return new Title($rich);
    }

    /**
     * The header's fill, the grid, the columns' widths.
     */
    protected function frame(Worksheet $sheet, int $last_col, int $last_row): void
    {
        $last_col = max(1, $last_col);
        $last_row = max(static::HEADER_ROW, $last_row);
        $right = Coordinate::stringFromColumnIndex($last_col);
        $header = 'A' . static::HEADER_ROW . ':' . $right . static::HEADER_ROW;
        $sheet->getStyle($header)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($header)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(static::HEADER_FILL);
        $sheet->getStyle('A' . static::HEADER_ROW . ':' . $right . $last_row)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(static::BORDER);

        // a column as wide as its longest text (a CJK character counts 2), within reason
        for ($col = 1; $col <= $last_col; $col++) {
            $width = 0;
            for ($row = static::HEADER_ROW; $row <= $last_row; $row++) {
                $value = $sheet->getCell([$col, $row])->getValue();
                if (is_string($value) && strpos($value, '=') !== 0) {
                    $width = max($width, mb_strwidth($value));
                }
            }
            $sheet->getColumnDimensionByColumn($col)->setWidth(min(50, max($col === 1 ? 12 : 11, $width * 1.1 + 2)));
        }
    }

    /**
     * A text cell: written as text whatever it looks like — a label "=1+2", "-5", "@x" is never a
     * formula — and one Excel would take for a formula on editing keeps its quote (Excel's ').
     *
     * @param string|array{0: int, 1: int} $coordinate
     */
    protected function text(Worksheet $sheet, $coordinate, string $text): void
    {
        $sheet->setCellValueExplicit($coordinate, $text, DataType::TYPE_STRING);
        if (preg_match('/^[=+\-@\t\r]/u', $text)) {
            $sheet->getStyle($coordinate)->setQuotePrefix(true);
        }
    }

    /**
     * A value cell: a number as a number (a percent as its fraction, formatted with %), an empty
     * value left blank as on the chart (no 0), anything else as text.
     *
     * @param string|array{0: int, 1: int} $coordinate
     * @param mixed $value
     */
    protected function number(Worksheet $sheet, $coordinate, $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (!is_numeric($value)) {
            $this->text($sheet, $coordinate, is_scalar($value) ? (string) $value : '');
            return;
        }
        $n = $value + 0;
        $sheet->setCellValueExplicit($coordinate, !empty($this->data['percent']) ? $n / 100 : $n, DataType::TYPE_NUMERIC);
    }

    /**
     * The value cells' format: a percent to 1 decimal as the chart shows it, a number grouped by
     * thousands with as many decimals as its values have (up to 10, as the chart's).
     */
    protected function numberFormat(): string
    {
        if (!empty($this->data['percent'])) {
            return '0.0%';
        }
        $values = [];
        foreach ($this->data['matrix'] as $row) {
            foreach ($row as $value) {
                $values[] = $value;
            }
        }
        return static::formatOf($values);
    }

    /**
     * Numbers grouped by thousands with as many decimals as $values have (up to 10, as the chart's).
     *
     * @param array<int, mixed> $values
     */
    protected static function formatOf(array $values): string
    {
        $decimals = 0;
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $fixed = rtrim(rtrim(sprintf('%.10F', $value + 0), '0'), '.');
                $dot = strpos($fixed, '.');
                $decimals = max($decimals, $dot === false ? 0 : strlen($fixed) - $dot - 1);
            }
        }
        return $decimals === 0 ? '#,##0' : '#,##0.0' . str_repeat('#', $decimals - 1);
    }

    /**
     * A color as Excel's RRGGBB: from #rrggbb / #rgb; null for any other (a name, rgba()).
     *
     * @param mixed $color
     */
    protected static function rgb($color): ?string
    {
        if (!is_string($color) || !preg_match('/^#?([0-9a-f]{6}|[0-9a-f]{3})$/i', trim($color), $m)) {
            return null;
        }
        $hex = $m[1];
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return strtoupper($hex);
    }
}
