<?php

namespace Exceedone\Exment\Services\Sync;

use Carbon\Carbon;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\SyncMapping;

/**
 * The values of an item of the service as the values of a record (ISS00113): the fields of the
 * mapping to its columns, each made the value its column type keeps. A user is found by its mail
 * address; a value that cannot be one (a user not in Exment, a date that is no date) is left out
 * and told (the dry run lists it).
 */
class FieldMapper
{
    /** @var array<string, int|null> mail address => user id, for one run */
    protected array $users = [];

    /**
     * @return array{0: array<string, mixed>, 1: array<int, string>} the values, and the notes
     */
    public function map(SyncMapping $mapping, CustomTable $table, RemoteItem $item): array
    {
        $values = [];
        $notes = [];
        foreach ((array)($mapping->field_map ?? []) as $entry) {
            $remote = strval(array_get($entry, 'remote'));
            $name = strval(array_get($entry, 'column'));
            if ($remote === '' || $name === '') {
                continue;
            }
            /** @var CustomColumn|null $column */
            $column = CustomColumn::getEloquent($name, $table);
            if (!isset($column)) {
                $notes[] = strval(exmtrans('sync.note.no_column', ['column' => $name]));
                continue;
            }
            if (!array_key_exists($remote, $item->values)) {
                continue;
            }
            [$value, $note] = $this->value($column, $item->values[$remote]);
            if (isset($note)) {
                $notes[] = $note;
            }
            $values[$name] = $value;
        }
        return [$values, $notes];
    }

    /**
     * @param mixed $value
     * @return array{0: mixed, 1: string|null}
     */
    public function value(CustomColumn $column, $value): array
    {
        if (is_null($value) || $value === '' || $value === []) {
            return [null, null];
        }
        $multiple = $column->isMultipleEnabled();
        if (is_array($value) && !$multiple && !in_array($column->column_type, [ColumnType::USER], true)) {
            $value = implode(', ', array_map('strval', $value));
        }
        switch ($column->column_type) {
            case ColumnType::INTEGER:
                return is_numeric($value) ? [intval($value), null] : [null, $this->note($column, $value)];
            case ColumnType::DECIMAL:
            case ColumnType::CURRENCY:
                return is_numeric($value) ? [floatval($value), null] : [null, $this->note($column, $value)];
            case ColumnType::DATE:
                return $this->date($column, $value, 'Y-m-d');
            case ColumnType::DATETIME:
                return $this->date($column, $value, 'Y-m-d H:i:s');
            case ColumnType::YESNO:
                return [boolval($value) && !in_array(strtolower(strval($value)), ['0', 'false', 'no'], true) ? 1 : 0, null];
            case ColumnType::USER:
                $list = is_array($value) ? $value : [$value];
                $ids = [];
                foreach ($list as $one) {
                    $id = $this->userId(strval($one));
                    if (isset($id)) {
                        $ids[] = $id;
                    } else {
                        return [null, strval(exmtrans('sync.note.no_user', ['column' => $column->column_view_name, 'value' => strval($one)]))];
                    }
                }
                return [$multiple ? $ids : ($ids[0] ?? null), null];
        }
        if ($multiple && is_array($value)) {
            return [array_values(array_map('strval', $value)), null];
        }
        return [is_scalar($value) ? strval($value) : strval(json_encode($value, JSON_UNESCAPED_UNICODE)), null];
    }

    /**
     * @param mixed $value
     * @return array{0: string|null, 1: string|null}
     */
    protected function date(CustomColumn $column, $value, string $format): array
    {
        try {
            $date = $value instanceof Carbon ? $value : Carbon::parse(strval($value));
            return [$date->setTimezone(config('app.timezone'))->format($format), null];
        } catch (\Throwable $e) {
            return [null, $this->note($column, $value)];
        }
    }

    /**
     * @param mixed $value
     */
    protected function note(CustomColumn $column, $value): string
    {
        return strval(exmtrans('sync.note.bad_value', ['column' => $column->column_view_name, 'value' => mb_strimwidth(strval(is_scalar($value) ? $value : json_encode($value)), 0, 60, '…')]));
    }

    protected function userId(string $value): ?int
    {
        $key = mb_strtolower(trim($value));
        if (!array_key_exists($key, $this->users)) {
            $id = null;
            if (filter_var($key, FILTER_VALIDATE_EMAIL)) {
                $id = getModelName(SystemTableName::USER)::query()->where('value->email', $key)->value('id');
            } elseif (is_numeric($key)) {
                $id = getModelName(SystemTableName::USER)::query()->whereKey(intval($key))->value('id');
            }
            $this->users[$key] = is_null($id) ? null : intval($id);
        }
        return $this->users[$key];
    }
}
