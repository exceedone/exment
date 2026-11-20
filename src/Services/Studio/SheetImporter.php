<?php

namespace Exceedone\Exment\Services\Studio;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Facades\DB;

/**
 * Studio, phase 2: take the rows of the read sheet into the table made from it.
 *
 * Each item of the screen remembers the column of the sheet it came from
 * ("source"). A value that does not fit the part the item has now - the user
 * may have changed it - is left empty and counted, so the page can say so.
 * The rows go in in one transaction: all of them or none.
 */
class SheetImporter
{
    /**
     * @param array<int, array<int, string>> $rows the data rows of the sheet
     * @param array<int, array{column_name: string, type: string, source: ?int}> $fields the items of the saved screen
     * @return array{saved: int, cleared: int}
     */
    public static function import(CustomTable $table, array $rows, array $fields): array
    {
        $mapped = array_values(array_filter($fields, function ($field) {
            return isset($field['source']) && $field['source'] >= 0;
        }));
        if (count($mapped) === 0) {
            return ['saved' => 0, 'cleared' => 0];
        }

        $saved = 0;
        $cleared = 0;
        DB::transaction(function () use ($table, $rows, $mapped, &$saved, &$cleared) {
            foreach ($rows as $row) {
                $value = [];
                foreach ($mapped as $field) {
                    $raw = trim(strval($row[$field['source']] ?? ''));
                    if ($raw === '') {
                        continue;
                    }
                    $converted = static::convert($field['type'], $raw);
                    if ($converted === null) {
                        $cleared++;
                        continue;
                    }
                    $value[$field['column_name']] = $converted;
                }
                if (count($value) === 0) {
                    continue;
                }
                $model = $table->getValueModel();
                if ($model === null) {
                    continue;
                }
                $model->setValue($value);
                $model->saveOrFail();
                $saved++;
            }
        });
        return ['saved' => $saved, 'cleared' => $cleared];
    }

    /**
     * The value to save for a cell in a column of the type; null when it does not fit.
     *
     * @return string|int|null
     */
    public static function convert(string $type, string $raw)
    {
        switch ($type) {
            case ColumnType::INTEGER:
                $number = str_replace(',', '', $raw);
                return preg_match('/^-?\d{1,15}$/', $number) === 1 ? intval($number) : null;
            case ColumnType::DECIMAL:
                $number = str_replace(',', '', $raw);
                return is_numeric($number) ? $number : null;
            case ColumnType::DATE:
                return SheetReader::toDateTime($raw, 'Y-m-d');
            case ColumnType::DATETIME:
                return SheetReader::toDateTime($raw, 'Y-m-d H:i:s') ?? (($date = SheetReader::toDateTime($raw, 'Y-m-d')) !== null ? $date . ' 00:00:00' : null);
            case ColumnType::TIME:
                return preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $raw, $m) === 1 && intval($m[1]) < 24 && intval($m[2]) < 60
                    ? sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0) : null;
            case ColumnType::YESNO:
                return SheetReader::toYesNo($raw);
            case ColumnType::EMAIL:
                return filter_var($raw, FILTER_VALIDATE_EMAIL) !== false ? $raw : null;
            case ColumnType::URL:
                return preg_match('#^https?://\S+$#i', $raw) === 1 ? $raw : null;
            case ColumnType::TEXT:
                return mb_substr($raw, 0, 256) === $raw ? $raw : null;
            case ColumnType::TEXTAREA:
            case ColumnType::SELECT:
                return $raw;
            default:
                // a user, a file or an image cannot be read from a cell
                return null;
        }
    }
}
