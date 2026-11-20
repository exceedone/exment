<?php

namespace Exceedone\Exment\Services\Studio;

use Exceedone\Exment\Enums\ColumnType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Studio, phase 2: read an Excel or CSV file to make a screen from it (like Power Apps).
 *
 * The first row is the names of the items, the rows under it the data. Each
 * column becomes an item of the builder, with the part guessed from its
 * values: numbers, dates, mail addresses, a few values repeated (a choice)...
 * The user changes what is guessed wrong before saving, and may take the
 * data in with the new table (SheetImporter).
 */
class SheetReader
{
    /** The data rows read (the rows under them are not taken in). */
    public const MAX_ROWS = 1000;
    public const MAX_FILE_MB = 10;
    public const EXTENSIONS = ['xlsx', 'csv'];

    /** A column with this many different values at most, each used twice on average, is a choice. */
    public const MAX_CHOICES = 15;

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>, truncated: bool}
     */
    public static function read(string $path, string $extension): array
    {
        $table = strtolower($extension) === 'csv' ? static::readCsv($path) : static::readXlsx($path);

        // the first row that has a value is the names of the items
        while (count($table) > 0 && static::isEmptyRow($table[0])) {
            array_shift($table);
        }
        $headers = count($table) > 0 ? array_map('strval', array_shift($table)) : [];

        // the last column that has a name or a value
        $width = 0;
        foreach (array_merge([$headers], $table) as $row) {
            for ($i = count($row) - 1; $i >= 0; $i--) {
                if (trim(strval($row[$i] ?? '')) !== '') {
                    $width = max($width, $i + 1);
                    break;
                }
            }
        }
        $width = min($width, BlankFormBuilder::MAX_FIELDS);

        $rows = [];
        foreach ($table as $row) {
            if (static::isEmptyRow($row)) {
                continue;
            }
            $cells = [];
            for ($i = 0; $i < $width; $i++) {
                $cells[] = trim(strval($row[$i] ?? ''));
            }
            $rows[] = $cells;
        }
        $truncated = count($rows) > static::MAX_ROWS;

        $names = [];
        for ($i = 0; $i < $width; $i++) {
            $name = trim(preg_replace('/\s+/u', ' ', strval($headers[$i] ?? '')) ?? '');
            $names[] = $name !== '' ? mb_substr($name, 0, BlankFormBuilder::MAX_VIEW_NAME_LENGTH)
                : sprintf(exmtrans('studio.sheet_column'), Coordinate::stringFromColumnIndex($i + 1));
        }

        return [
            'headers' => $names,
            'rows' => array_slice($rows, 0, static::MAX_ROWS),
            'truncated' => $truncated,
        ];
    }

    /**
     * The items of the builder for the columns of a sheet, with the guessed parts.
     *
     * @param array{headers: array<int, string>, rows: array<int, array<int, string>>, truncated: bool} $sheet
     * @return array<int, array{label: string, type: string, choices: string, source: int, column: string}>
     */
    public static function fields(array $sheet): array
    {
        $fields = [];
        foreach ($sheet['headers'] as $index => $label) {
            $values = [];
            foreach ($sheet['rows'] as $row) {
                if (($row[$index] ?? '') !== '') {
                    $values[] = $row[$index];
                }
            }
            [$type, $choices] = static::guess($values);
            $fields[] = [
                'label' => $label,
                'type' => $type,
                'choices' => $choices,
                'source' => $index,
                'column' => Coordinate::stringFromColumnIndex($index + 1),
            ];
        }
        return $fields;
    }

    /**
     * The part for the values of a column, and the choices when it is a choice.
     *
     * @param array<int, string> $values the values that are not empty
     * @return array{0: string, 1: string}
     */
    public static function guess(array $values): array
    {
        if (count($values) === 0) {
            return [ColumnType::TEXT, ''];
        }
        $all = function (callable $test) use ($values) {
            foreach ($values as $value) {
                if (!$test($value)) {
                    return false;
                }
            }
            return true;
        };

        if ($all(function ($v) {
            return filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
        })) {
            return [ColumnType::EMAIL, ''];
        }
        if ($all(function ($v) {
            return preg_match('#^https?://\S+$#i', $v) === 1;
        })) {
            return [ColumnType::URL, ''];
        }
        if ($all(function ($v) {
            return static::toDateTime($v, 'Y-m-d') !== null && preg_match('/\d{1,2}:\d{2}/', $v) !== 1;
        })) {
            return [ColumnType::DATE, ''];
        }
        if ($all(function ($v) {
            return static::toDateTime($v, 'Y-m-d H:i:s') !== null;
        })) {
            return [ColumnType::DATETIME, ''];
        }
        if ($all(function ($v) {
            return preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $v) === 1;
        })) {
            return [ColumnType::TIME, ''];
        }
        // a code with a leading zero ("007") keeps its zero as text
        if ($all(function ($v) {
            return preg_match('/^-?(0|[1-9]\d{0,2}(,\d{3})*|[1-9]\d*)$/', $v) === 1 && strlen(str_replace([',', '-'], '', $v)) <= 15;
        })) {
            return [ColumnType::INTEGER, ''];
        }
        if ($all(function ($v) {
            // a long number (an account or a card number) is not a quantity: it stays text
            return preg_match('/^-?(0|[1-9]\d{0,2}(,\d{3})*|[1-9]\d*)(\.\d+)?$/', $v) === 1 && strlen(str_replace([',', '-', '.'], '', $v)) <= 15;
        })) {
            return [ColumnType::DECIMAL, ''];
        }
        if ($all(function ($v) {
            return static::toYesNo($v) !== null && !is_numeric($v);
        })) {
            return [ColumnType::YESNO, ''];
        }

        $long = !$all(function ($v) {
            return mb_strlen($v) <= 100 && strpos($v, "\n") === false;
        });
        if ($long) {
            return [ColumnType::TEXTAREA, ''];
        }

        $distinct = array_values(array_unique($values));
        if (count($distinct) <= static::MAX_CHOICES && count($values) >= count($distinct) * 2) {
            return [ColumnType::SELECT, implode("\n", $distinct)];
        }
        return [ColumnType::TEXT, ''];
    }

    /**
     * A date or a date and time written as Excel or people write it, in the given format; null when it is not one.
     */
    public static function toDateTime(string $value, string $format): ?string
    {
        $value = trim($value);
        if (preg_match('#^(\d{4})[-/.年](\d{1,2})[-/.月](\d{1,2})日?(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$#u', $value, $m) !== 1) {
            return null;
        }
        if (!checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
            return null;
        }
        $hour = $m[4] ?? '';
        $minute = intval($m[5] ?? 0);
        $second = intval($m[6] ?? 0);
        $hasTime = $hour !== '';
        if ($format === 'Y-m-d H:i:s' && !$hasTime) {
            return null;
        }
        if ($hasTime && (intval($hour) > 23 || $minute > 59)) {
            return null;
        }
        $date = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        if ($format === 'Y-m-d') {
            return $date;
        }
        return sprintf('%s %02d:%02d:%02d', $date, intval($hour), $minute, $second);
    }

    /**
     * 1 or 0 for a yes or no written in Japanese, English or as a mark; null otherwise.
     */
    public static function toYesNo(string $value): ?int
    {
        $v = mb_strtolower(trim($value));
        if (in_array($v, ['1', 'yes', 'y', 'true', 'on', 'はい', '○', '〇', '有', 'あり', '済'], true)) {
            return 1;
        }
        if (in_array($v, ['0', 'no', 'n', 'false', 'off', 'いいえ', '×', '無', 'なし', '未'], true)) {
            return 0;
        }
        return null;
    }

    /**
     * @param array<int, mixed> $row
     */
    protected static function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim(strval($value)) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * The cells of the first sheet. A date cell is read as people see it ("2026-11-21").
     *
     * @return array<int, array<int, string>>
     */
    protected static function readXlsx(string $path): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadEmptyCells(false);
        $sheet = $reader->load($path)->getSheet(0);

        $result = [];
        $maxRow = min($sheet->getHighestDataRow(), static::MAX_ROWS + 50);
        $maxCol = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), BlankFormBuilder::MAX_FIELDS + 20);
        for ($r = 1; $r <= $maxRow; $r++) {
            $row = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($c) . $r);
                $value = $cell->getCalculatedValue();
                if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
                    $date = ExcelDate::excelToDateTimeObject(floatval($value));
                    if (floatval($value) < 1) {
                        $value = $date->format('H:i');
                    } elseif (floor(floatval($value)) == floatval($value)) {
                        $value = $date->format('Y-m-d');
                    } else {
                        $value = $date->format('Y-m-d H:i:s');
                    }
                } elseif (is_bool($value)) {
                    $value = $value ? 'TRUE' : 'FALSE';
                } elseif (is_float($value)) {
                    // 0.1 + 0.2 shows as 0.3 in Excel: keep what is shown, not the float noise
                    $value = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
                }
                $row[] = strval($value ?? '');
            }
            $result[] = $row;
        }
        return $result;
    }

    /**
     * The rows of a CSV. Shift_JIS (CSV saved by Excel in Japan) is read as well as UTF-8.
     *
     * @return array<int, array<int, string>>
     */
    protected static function readCsv(string $path): array
    {
        $raw = strval(file_get_contents($path));
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        } elseif (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = strval(mb_convert_encoding($raw, 'UTF-8', 'SJIS-win'));
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return [];
        }
        fwrite($stream, $raw);
        rewind($stream);
        $result = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false && count($result) <= static::MAX_ROWS + 50) {
            $result[] = array_map(function ($v) {
                return str_replace(["\r\n", "\r"], "\n", strval($v));
            }, $row);
        }
        fclose($stream);
        return $result;
    }
}
