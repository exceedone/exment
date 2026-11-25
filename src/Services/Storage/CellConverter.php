<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Model\CustomColumn;

/**
 * The one conversion of a single-valued cell from its JSON value to its typed physical value,
 * shared by the model dual-write and (later) the backfill (02 §15.4 "Chuyển đổi ô").
 *
 * Input: the JSON-decoded value of the cell (never null: a null/absent cell is not converted, it
 * is written as SQL NULL with no value_original entry by the caller).
 * Output: ConvertedCell(typed, lossless). lossless = (render(typed) === original), render per
 * 02 §15.2: integer / select_table / user / organization -> strval(int); decimal -> canonical
 * form D (no trailing fractional zeros, no trailing point); date 'Y-m-d'; datetime
 * 'Y-m-d H:i:s'; time 'H:i:s'; yesno '0'/'1'; strings verbatim.
 *
 * A value the type cannot hold (wrong kind, out of range, too long, invalid date, object or
 * array) converts to typed NULL, not lossless: the original stays in value_original and the
 * cell's query semantics change as listed in 02 §15.5 (L5). Nothing is ever truncated or coerced
 * into a different typed value, except decimal rounding beyond 8 fractional digits (not
 * lossless) and the date/time part of a Carbon array or of a longer date string (not lossless).
 */
final class CellConverter
{
    /** decimal(22,8): 14 integer digits, 8 fractional digits (02 §11.2). */
    public const DECIMAL_INTEGER_DIGITS = 14;
    public const DECIMAL_FRACTION_DIGITS = 8;
    /** bigint written from PHP int: up to 18 digits (no overflow of a 64-bit int). */
    public const BIGINT_MAX_DIGITS = 18;
    /** unsigned int (MySQL/MariaDB) and int (SQL Server): the smaller maximum. */
    public const UINT_MAX = 2147483647;
    public const VARCHAR255 = 255;
    public const VARCHAR768 = 768;
    /** mediumtext: bytes. */
    public const MEDIUMTEXT_MAX_BYTES = 16777215;

    public static function convert(CustomColumn $column, $value): ConvertedCell
    {
        $type = PhysicalColumnType::of($column);
        if ($type === null) {
            throw new \InvalidArgumentException("column {$column->column_name} has no physical type");
        }
        return static::convertAs($type, $value);
    }

    /**
     * @param mixed $value JSON-decoded, not null
     */
    public static function convertAs(string $type, $value): ConvertedCell
    {
        switch ($type) {
            case PhysicalColumnType::MEDIUMTEXT:
                return static::string($value, null, self::MEDIUMTEXT_MAX_BYTES);
            case PhysicalColumnType::VARCHAR255:
                return static::string($value, self::VARCHAR255, null);
            case PhysicalColumnType::VARCHAR768:
                return static::string($value, self::VARCHAR768, null);
            case PhysicalColumnType::BIGINT:
                return static::integer($value, self::BIGINT_MAX_DIGITS, null);
            case PhysicalColumnType::UINT:
                return static::integer($value, 10, self::UINT_MAX);
            case PhysicalColumnType::DECIMAL:
                return static::decimal($value);
            case PhysicalColumnType::DATE:
                return static::date($value);
            case PhysicalColumnType::DATETIME:
                return static::datetime($value);
            case PhysicalColumnType::TIME:
                return static::time($value);
            case PhysicalColumnType::TINYINT:
                return static::yesno($value);
        }
        throw new \InvalidArgumentException("unknown physical type $type");
    }

    // ---------------------------------------------------------------- strings

    private static function string($value, ?int $maxChars, ?int $maxBytes): ConvertedCell
    {
        if (is_string($value)) {
            $text = $value;
            $lossless = true;
        } elseif (is_int($value) || is_float($value)) {
            $text = json_encode($value); // the number as JSON writes it
            $lossless = false;
        } elseif (is_bool($value)) {
            $text = $value ? '1' : '0';
            $lossless = false;
        } else {
            return new ConvertedCell(null, false, 'not_scalar');
        }
        if ($maxChars !== null && static::storedLength($text) > $maxChars) {
            return new ConvertedCell(null, false, 'too_long');
        }
        if ($maxBytes !== null && strlen($text) > $maxBytes) {
            return new ConvertedCell(null, false, 'too_long');
        }
        return new ConvertedCell($text, $lossless, $lossless ? null : 'json_type');
    }

    /**
     * Length of a text as the column type counts it: MySQL / MariaDB varchar(n) counts characters;
     * SQL Server nvarchar(n) counts UTF-16 code units (a character outside the BMP takes two).
     */
    private static function storedLength(string $text): int
    {
        if (PhysicalSchemaManager::driverFamily() === 'sqlsrv') {
            return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
        }
        return mb_strlen($text, 'UTF-8');
    }

    // ---------------------------------------------------------------- numbers

    private static function integer($value, int $maxDigits, ?int $max): ConvertedCell
    {
        if (is_int($value)) {
            $digits = ltrim((string)abs($value), '0');
            $typed = $value;
            $fromString = false;
        } elseif (is_float($value) && floor($value) === $value && abs($value) < 1e18) {
            $typed = (int)$value;
            $digits = ltrim((string)abs($typed), '0');
            $fromString = false;
        } elseif (is_string($value) && preg_match('/^(-?)(\d+)$/', $value, $m)) {
            $digits = ltrim($m[2], '0');
            if (strlen($digits) > $maxDigits) {
                return new ConvertedCell(null, false, 'out_of_range');
            }
            $typed = (int)($m[1] . ($digits === '' ? '0' : $digits));
            $fromString = true;
        } else {
            return new ConvertedCell(null, false, $value === '' ? 'empty' : 'invalid');
        }
        if (strlen($digits) > $maxDigits || ($max !== null && ($typed < 0 || $typed > $max))) {
            return new ConvertedCell(null, false, 'out_of_range');
        }
        $lossless = $fromString && strval($typed) === $value;
        return new ConvertedCell($typed, $lossless, $lossless ? null : ($fromString ? 'text_form' : 'json_type'));
    }

    private static function decimal($value): ConvertedCell
    {
        if (is_string($value)) {
            $text = $value;
            $fromString = true;
        } elseif (is_int($value) || is_float($value)) {
            $text = is_int($value) ? (string)$value : json_encode($value);
            $fromString = false;
        } else {
            return new ConvertedCell(null, false, 'invalid');
        }
        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', (string)$text, $m)) {
            return new ConvertedCell(null, false, $value === '' ? 'empty' : 'invalid');
        }
        $negative = $m[1] === '-';
        $int = ltrim($m[2], '0');
        $frac = $m[3] ?? '';
        $rounded = false;
        if (strlen($frac) > self::DECIMAL_FRACTION_DIGITS) {
            [$int, $frac] = static::roundHalfUp($int, $frac, self::DECIMAL_FRACTION_DIGITS);
            $rounded = true;
        }
        if (strlen($int) > self::DECIMAL_INTEGER_DIGITS) {
            return new ConvertedCell(null, false, 'out_of_range');
        }
        $frac = str_pad($frac, self::DECIMAL_FRACTION_DIGITS, '0');
        $isZero = trim($int . $frac, '0') === '';
        $typed = ($negative && !$isZero ? '-' : '') . ($int === '' ? '0' : $int) . '.' . $frac;

        $lossless = $fromString && !$rounded && static::decimalCanonical($typed) === $value;
        return new ConvertedCell($typed, $lossless, $lossless ? null : ($rounded ? 'rounded' : ($fromString ? 'text_form' : 'json_type')));
    }

    /**
     * Canonical form D of a typed decimal "-12.50000000" -> "-12.5", "12.00000000" -> "12".
     */
    public static function decimalCanonical(string $typed): string
    {
        if (strpos($typed, '.') === false) {
            return $typed;
        }
        $out = rtrim(rtrim($typed, '0'), '.');
        return $out === '-0' ? '0' : $out;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function roundHalfUp(string $int, string $frac, int $scale): array
    {
        $keep = substr($frac, 0, $scale);
        if ((int)$frac[$scale] < 5) {
            return [$int, $keep];
        }
        $digits = str_split(($int === '' ? '0' : $int) . $keep);
        for ($i = count($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] === '9') {
                $digits[$i] = '0';
                continue;
            }
            $digits[$i] = (string)((int)$digits[$i] + 1);
            break;
        }
        $all = ($i < 0 ? '1' : '') . implode('', $digits);
        return [ltrim(substr($all, 0, strlen($all) - $scale), '0'), substr($all, -$scale)];
    }

    // ---------------------------------------------------------------- dates

    private static function date($value): ConvertedCell
    {
        [$text, $fromCarbon] = static::dateText($value);
        if ($text === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m) || !static::validDate($m)) {
            return new ConvertedCell(null, false, $value === '' ? 'empty' : 'invalid');
        }
        $typed = substr($text, 0, 10);
        $lossless = !$fromCarbon && $typed === $value;
        return new ConvertedCell($typed, $lossless, $lossless ? null : ($fromCarbon ? 'carbon' : 'text_form'));
    }

    private static function datetime($value): ConvertedCell
    {
        [$text, $fromCarbon] = static::dateText($value);
        if ($text === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/', $text, $m) || !static::validDate($m)) {
            return new ConvertedCell(null, false, $value === '' ? 'empty' : 'invalid');
        }
        $h = $m[4] ?? '00';
        $i = $m[5] ?? '00';
        $s = $m[6] ?? '00';
        if (!static::validTime($h, $i, $s)) {
            return new ConvertedCell(null, false, 'invalid');
        }
        if ((int)$m[1] < static::minDatetimeYear()) {
            return new ConvertedCell(null, false, 'out_of_range');
        }
        $typed = "{$m[1]}-{$m[2]}-{$m[3]} $h:$i:$s";
        $lossless = !$fromCarbon && $typed === $value;
        return new ConvertedCell($typed, $lossless, $lossless ? null : ($fromCarbon ? 'carbon' : 'text_form'));
    }

    private static function time($value): ConvertedCell
    {
        if (!is_string($value) || !preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
            return new ConvertedCell(null, false, $value === '' ? 'empty' : 'invalid');
        }
        $s = $m[3] ?? '00';
        if (!static::validTime($m[1], $m[2], $s)) {
            return new ConvertedCell(null, false, 'invalid');
        }
        $typed = "{$m[1]}:{$m[2]}:$s";
        $lossless = $typed === $value;
        return new ConvertedCell($typed, $lossless, $lossless ? null : 'text_form');
    }

    /**
     * A date string, or the 'date' of the array form of a Carbon (V-7).
     *
     * @return array{0: string|null, 1: bool} [text, from carbon array]
     */
    private static function dateText($value): array
    {
        if (is_string($value)) {
            return [$value, false];
        }
        if (is_array($value) && isset($value['date']) && is_string($value['date'])) {
            // the wall-clock time stored in the array, no timezone conversion [CHƯA KIỂM CHỨNG]
            return [$value['date'], true];
        }
        return [null, false];
    }

    /** @param array<int|string, string> $m [1] year, [2] month, [3] day */
    private static function validDate(array $m): bool
    {
        $y = (int)$m[1];
        return $y >= 1000 && $y <= 9999 && checkdate((int)$m[2], (int)$m[3], $y);
    }

    private static function validTime(string $h, string $i, string $s): bool
    {
        return (int)$h <= 23 && (int)$i <= 59 && (int)$s <= 59;
    }

    /** SQL Server `datetime` (Blueprint dateTime without precision) starts in 1753. */
    private static function minDatetimeYear(): int
    {
        return PhysicalSchemaManager::driverFamily() === 'sqlsrv' ? 1753 : 1000;
    }

    // ---------------------------------------------------------------- yes/no

    private static function yesno($value): ConvertedCell
    {
        if ($value === '1' || $value === '0') {
            return new ConvertedCell((int)$value, true);
        }
        if ($value === 1 || $value === 0 || $value === true || $value === false) {
            return new ConvertedCell((int)$value, false, 'json_type');
        }
        return new ConvertedCell(null, false, $value === '' ? 'empty' : 'invalid');
    }
}
