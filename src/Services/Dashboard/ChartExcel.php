<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Enums\ChartType;
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
 * header, its rows below; the chart under the table.
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
     * The download's name: the box title and the date, as the CSV's (売上_20261107.xlsx).
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
     * 合計 per row where the series stack and add up, a 割合 per slice of a pie that adds up.
     *
     * @return array{last_row: int, last_col: int, series: int}  the table's extent
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

        $this->frame($sheet, $last_col, $last_row);
        return ['last_row' => $last_row, 'last_col' => $last_col, 'series' => count($series)];
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
     * Excel's chart of the table, under it, in the box's colors.
     *
     * @param array{last_row: int, last_col: int, series: int} $table
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
        $labels = [];
        $values = [];
        for ($s = 0; $s < $table['series']; $s++) {
            $col = Coordinate::stringFromColumnIndex($s + 2);
            $label = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$quoted}!\${$col}\$" . static::HEADER_ROW, null, 1);
            $value = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "{$quoted}!\${$col}\${$top}:\${$col}\${$last}", null, $count);
            $color = static::rgb($this->data['colors'][$s % max(1, count($this->data['colors']))] ?? null);
            if ($per_point) {
                $value->setFillColor(array_map(function ($c) {
                    return static::rgb($c) ?? '';
                }, array_values($this->data['point_colors'])));
            } elseif ($color !== null) {
                if (in_array($chart_type, [DataSeries::TYPE_LINECHART, DataSeries::TYPE_RADARCHART], true)) {
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
            if (in_array($chart_type, [DataSeries::TYPE_LINECHART, DataSeries::TYPE_RADARCHART], true)) {
                $value->setPointMarker('circle');
                $value->setPointSize($type === ChartType::SCATTER ? 9 : 5);
                $value->setScatterLines($type !== ChartType::SCATTER);
            }
            $labels[] = $label;
            $values[] = $value;
        }
        $categories = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$quoted}!\$A\${$top}:\$A\${$last}", null, $count)];
        $series = new DataSeries($chart_type, $grouping, range(0, count($values) - 1), $labels, $categories, $values, $direction, false, $chart_type === DataSeries::TYPE_RADARCHART ? DataSeries::STYLE_MARKER : null);
        // the multi-series lines are curves on the chart, the single one is straight
        $series->setSmoothLine($type === ChartType::MLINE);

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
        } elseif (ChartType::isMulti($type) && !empty($this->data['legend'])) {
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

        $chart = new Chart(
            'chart1',
            new Title((string) $this->data['title']),
            $legend,
            new PlotArea($layout, [$series]),
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
        $decimals = 0;
        foreach ($this->data['matrix'] as $row) {
            foreach ($row as $value) {
                if (is_numeric($value)) {
                    $fixed = rtrim(rtrim(sprintf('%.10F', $value + 0), '0'), '.');
                    $dot = strpos($fixed, '.');
                    $decimals = max($decimals, $dot === false ? 0 : strlen($fixed) - $dot - 1);
                }
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
