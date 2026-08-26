<?php

namespace Exceedone\Exment\Services\Migration\Concerns;

use Carbon\Carbon;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Services\Migration\Blueprint;
use Exceedone\Exment\Services\Migration\Sources\FileSource;

/**
 * Reading a source record and turning it into Exment column values.
 *
 * Split out of the service because it is the part that gets edited: every new
 * source system needs one more transform, and none of that has anything to do
 * with how the run is organised.
 */
trait MapsValues
{
    /**
     * @param string $name
     * @param array<string, mixed> $stream
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function mapRow(string $name, array $stream, array $row): array
    {
        $values = [];

        foreach ((array)array_get($stream, 'columns', []) as $column => $definition) {
            $definition = (array)$definition;

            if ($reference = array_get($definition, 'ref')) {
                // resolved against what is already imported; anything that
                // points at a record not written yet is noted and patched at
                // the end rather than silently dropped
                $values[$column] = $this->resolveReference($reference, $row, $definition);
                continue;
            }

            $raw = $this->pick($row, strval(array_get($definition, 'from', $column)));
            $values[$column] = $this->transform($raw, $definition, $row, $name . '.' . $column);
        }

        return $values;
    }

    /**
     * Read a dotted path out of a source record.
     *
     * Deliberately forgiving in one specific way. A ServiceNow API dump gives
     *
     *     "priority": {"value": "1", "display_value": "1 - Critical"}
     *
     * while the CSV export of the same table gives
     *
     *     "priority": "1 - Critical"
     *
     * A preset written against one should not break against the other, so a
     * path of "priority.value" that finds a plain string at "priority" uses the
     * string. Same preset, both dumps.
     *
     * @param array<string, mixed> $row
     * @param string $path
     * @return mixed
     */
    protected function pick(array $row, string $path)
    {
        if ($path === '') {
            return null;
        }

        $value = array_get($row, $path);
        if ($value !== null) {
            return $this->flatten($value);
        }

        // the path asked for a sub-field of something that turned out flat
        if (strpos($path, '.') !== false) {
            $parts = explode('.', $path);
            $last = array_pop($parts);

            if (in_array($last, ['value', 'display_value'])) {
                $parent = array_get($row, implode('.', $parts));
                if (is_scalar($parent)) {
                    return $parent;
                }
            }
        }

        return null;
    }

    /**
     * An object that carries both a stored value and a label collapses to the
     * label, which is what a person expects to see.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function flatten($value)
    {
        if (is_array($value) && array_key_exists('display_value', $value)) {
            $display = array_get($value, 'display_value');
            return is_nullorempty($display) ? array_get($value, 'value') : $display;
        }

        return $value;
    }

    /**
     * @param mixed $raw
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $row
     * @param string $where for messages
     * @return mixed
     */
    protected function transform($raw, array $definition, array $row, string $where)
    {
        if ($via = array_get($definition, 'via')) {
            $raw = $this->translate($via, $raw);
        }

        $transform = array_get($definition, 'transform');
        $type = array_get($definition, 'type', ColumnType::TEXT);

        if ($transform === null) {
            // an unstated transform is inferred from the column type, so a
            // preset only has to say something when the obvious is wrong
            $transform = $this->defaultTransform(strval($type));
        }

        switch ($transform) {
            case 'datetime':
                return $this->toDateTime($raw, $where);

            case 'date':
                $value = $this->toDateTime($raw, $where);
                return $value ? substr($value, 0, 10) : null;

            case 'user':
                return $this->toUser($raw, $where);

            case 'number':
                return is_numeric($raw) ? $raw + 0 : null;

            case 'yesno':
                return $this->toYesNo($raw);

            case 'names':
                return $this->toNames($raw, strval(array_get($definition, 'name_key', 'name')));

            case 'strip':
                return is_scalar($raw) ? trim(strip_tags(strval($raw))) : null;

            case 'json':
                return is_nullorempty($raw) ? null : json_encode($raw, JSON_UNESCAPED_UNICODE);

            case 'raw':
                return $raw;
        }

        if (is_array($raw)) {
            // never let an array reach a text column: it stores as "Array"
            return json_encode($raw, JSON_UNESCAPED_UNICODE);
        }

        return is_scalar($raw) ? strval($raw) : null;
    }

    /**
     * @param string $type
     * @return string|null
     */
    protected function defaultTransform(string $type)
    {
        switch ($type) {
            case ColumnType::DATETIME:
                return 'datetime';
            case ColumnType::DATE:
                return 'date';
            case ColumnType::USER:
                return 'user';
            case ColumnType::INTEGER:
            case ColumnType::DECIMAL:
            case ColumnType::CURRENCY:
                return 'number';
            case ColumnType::YESNO:
            case ColumnType::BOOLEAN:
                return 'yesno';
        }

        return null;
    }

    /**
     * Turn whatever the source called a time into what Exment stores.
     *
     * The timezone handling is the part that matters. Backlog stamps its times
     * with a Z, so they are unambiguous. ServiceNow's stored values are UTC but
     * carry no marker at all, and parsing those as local time puts every
     * timestamp in a Japanese instance nine hours out - consistently, quietly,
     * and in a way that looks plausible on screen.
     *
     * @param mixed $raw
     * @param string $where
     * @return string|null
     */
    protected function toDateTime($raw, string $where)
    {
        if (is_nullorempty($raw) || !is_scalar($raw)) {
            return null;
        }

        $text = trim(strval($raw));
        if ($text === '' || $text === '0000-00-00 00:00:00') {
            return null;
        }

        $explicit = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $text);
        $sourceZone = strval(array_get($this->preset, 'source_timezone', 'UTC'));

        try {
            $date = $explicit ? Carbon::parse($text) : Carbon::parse($text, $sourceZone);
        } catch (\Throwable $e) {
            $this->note(sprintf('%s: could not read "%s" as a date', $where, $text));
            return null;
        }

        return $date->setTimezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * An email, or something that can be turned into one, to an Exment user.
     *
     * @param mixed $raw
     * @param string $where
     * @return int|null
     */
    protected function toUser($raw, string $where)
    {
        if (is_nullorempty($raw)) {
            return null;
        }

        $email = strtolower(trim(strval($this->flatten($raw))));
        if ($email === '') {
            return null;
        }

        $id = array_get($this->userMap(), $email);

        if (!$id) {
            // named once, not once per ticket: a departed colleague appears on
            // hundreds of rows and would otherwise bury every other message
            $this->note(sprintf('no Exment user with the address "%s" (seen at %s)', $email, $where));
            return null;
        }

        return $id;
    }

    /**
     * @param mixed $raw
     * @return string|null
     */
    protected function toYesNo($raw)
    {
        if (is_nullorempty($raw)) {
            return null;
        }

        if (is_bool($raw)) {
            return $raw ? '1' : '0';
        }

        $text = strtolower(trim(strval($raw)));

        return in_array($text, ['1', 'true', 'yes', 'y', 'on']) ? '1' : '0';
    }

    /**
     * A list of objects to a readable list of their names.
     *
     * @param mixed $raw
     * @param string $nameKey
     * @return string|null
     */
    protected function toNames($raw, string $nameKey)
    {
        if (is_nullorempty($raw)) {
            return null;
        }

        if (!is_array($raw)) {
            return strval($this->flatten($raw));
        }

        // a single object rather than a list
        if (array_key_exists($nameKey, $raw)) {
            return strval(array_get($raw, $nameKey));
        }

        $names = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $name = array_get($item, $nameKey);
                if (!is_nullorempty($name)) {
                    $names[] = strval($name);
                }
            } elseif (is_scalar($item)) {
                $names[] = strval($item);
            }
        }

        return empty($names) ? null : implode(', ', $names);
    }

    /**
     * Anything that identifies a person => Exment user record id.
     *
     * Keyed on both the address and the login code, because the two source
     * systems name people differently and neither matches Exment's own idea by
     * default: ServiceNow hands over a user_name like "abel.tuter", Backlog a
     * userId, and both also carry the address. Accepting all of them turns a
     * pile of blank assignee fields into filled ones without anybody having to
     * hand-build a translation table.
     *
     * @return array<string, int>
     */
    protected function userMap(): array
    {
        if ($this->userMap !== null) {
            return $this->userMap;
        }

        $map = [];
        $userTable = CustomTable::getEloquent(SystemTableName::USER);

        if (!isset($userTable)) {
            return $this->userMap = $map;
        }

        $userTable->getValueModel()->newQuery()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->select(['id', 'value'])
            ->chunk(2000, function ($rows) use (&$map) {
                foreach ($rows as $row) {
                    foreach (['email', 'user_code'] as $field) {
                        $key = strtolower(trim(strval(array_get($row->value, $field, ''))));
                        // the address wins a collision: a user_code that
                        // happens to equal somebody else's address must not
                        // quietly reassign their tickets
                        if ($key !== '' && !array_key_exists($key, $map)) {
                            $map[$key] = intval($row->id);
                        }
                    }
                }
            });

        return $this->userMap = $map;
    }

    /**
     * Build a translation table out of a staged stream, e.g. a ServiceNow
     * sys_id to the email address on that user record.
     *
     * @param array<string, mixed> $via
     * @param mixed $raw
     * @return mixed
     */
    protected function translate(array $via, $raw)
    {
        if (is_nullorempty($raw)) {
            return null;
        }

        $stream = strval(array_get($via, 'stream'));
        $from = strval(array_get($via, 'key', 'id'));
        $to = strval(array_get($via, 'value', 'name'));
        $cacheKey = $stream . '|' . $from . '|' . $to;

        if (!array_key_exists($cacheKey, $this->viaMaps)) {
            $map = [];
            $file = new FileSource(['directory' => $this->directory]);

            foreach ($file->fetch($stream) as $row) {
                $key = $this->pick($row, $from);
                $value = $this->pick($row, $to);
                if (!is_nullorempty($key) && !is_nullorempty($value)) {
                    $map[strval($key)] = $value;
                }
            }

            if (empty($map)) {
                $this->note(sprintf('"%s" produced no %s to %s lookup; those fields will be empty', $stream, $from, $to));
            }

            $this->viaMaps[$cacheKey] = $map;
        }

        return array_get($this->viaMaps[$cacheKey], strval($this->flatten($raw)));
    }
}
