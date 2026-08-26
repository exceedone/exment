<?php

namespace Exceedone\Exment\Services;

use Carbon\Carbon;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;

/**
 * Lets an outside system - a monitoring tool, a CI server, a git host - put a
 * record into Exment by posting json at it.
 *
 * This is the missing half of the operations story. Alerts, builds and deploys
 * already have tables; until now somebody had to retype them. What makes the
 * loop close is not only creating the record but what follows it: a firing
 * alert raises an incident, and the same alert clearing again closes it.
 *
 * Like the SLA clock, no table is named here. Everything is configuration on
 * the custom table (`custom_tables.options.webhook`):
 *
 *   enabled   off by default; the endpoint refuses a table that has not opted in
 *   secret    shared secret, sent as X-Exment-Token or ?token=
 *   user      login user id the writes are attributed to (a service account)
 *   key       column that identifies the event, so a repeat updates rather than
 *             duplicates
 *   map       target column => dot path into the posted json
 *   values    target column => [incoming value => stored value]
 *   defaults  target column => value used when the payload does not carry one
 *   spawn     raise a record in another table (see spawn())
 *   close     finish the records this one raised (see close())
 *
 * A value in map/defaults starting with "@" is a literal rather than a lookup:
 * "@now" is the current time and "@new" is the plain string "new".
 */
class WebhookService
{
    public const OPTION_KEY = 'webhook';

    /** @var CustomTable */
    protected $table;

    /** @var array<string, mixed> */
    protected $setting;

    public function __construct(CustomTable $table)
    {
        $this->table = $table;
        $this->setting = (array)$table->getOption(static::OPTION_KEY);
    }

    /**
     * Whether this table accepts posted events at all.
     *
     * @return bool
     */
    public function enabled(): bool
    {
        return boolval(array_get($this->setting, 'enabled'));
    }

    /**
     * Whether the caller presented the right secret.
     *
     * @param string|null $token
     * @return bool
     */
    public function authenticate(?string $token): bool
    {
        $secret = strval(array_get($this->setting, 'secret', ''));
        if ($secret === '') {
            return false;
        }

        return is_string($token) && hash_equals($secret, $token);
    }

    /**
     * Login user the writes should be attributed to, if the table names one.
     *
     * @return int|null
     */
    public function serviceUserId()
    {
        $id = array_get($this->setting, 'user');
        return is_nullorempty($id) ? null : intval($id);
    }

    /**
     * Take one posted event.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> what happened, for the caller and the log
     */
    public function receive(array $payload): array
    {
        $values = $this->buildValues($payload, (array)array_get($this->setting, 'map'));
        if (empty($values)) {
            return ['status' => 'ignored', 'reason' => 'nothing in the payload matched the mapping'];
        }

        $record = $this->find($values);

        // Defaults fill in a new record. Applying them to one that already
        // exists would undo what an earlier call stored: a recovery message
        // carries only the key and the new status, and would otherwise reset
        // the severity and the time the alert first fired.
        if (!$record->exists) {
            foreach ((array)array_get($this->setting, 'defaults') as $column => $value) {
                if (!array_key_exists($column, $values)) {
                    $values[$column] = static::isLiteral($value) ? static::literal($value) : $value;
                }
            }
        }

        $record->setValue($values);
        $record->save();
        $result = [
            'status' => 'ok',
            'table' => $this->table->table_name,
            'id' => $record->id,
            'created' => boolval($record->wasRecentlyCreated),
        ];

        $spawned = $this->spawn($record);
        if ($spawned) {
            $result['spawned'] = $spawned;
        }

        $closed = $this->close($record);
        if ($closed) {
            $result['closed'] = $closed;
        }

        return $result;
    }

    /**
     * Turn the posted json into column values.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $map
     * @return array<string, mixed>
     */
    protected function buildValues(array $payload, array $map): array
    {
        $translate = (array)array_get($this->setting, 'values');

        $values = [];
        foreach ($map as $column => $path) {
            $raw = static::isLiteral($path) ? static::literal($path) : array_get($payload, $path);
            if (is_nullorempty($raw)) {
                continue;
            }
            if (is_array($raw)) {
                $raw = json_encode($raw, JSON_UNESCAPED_UNICODE);
            }
            $values[$column] = array_get($translate, $column . '.' . strval($raw), $raw);
        }

        return $values;
    }

    /**
     * The record this event already produced, or a fresh one to fill in.
     *
     * @param array<string, mixed> $values
     * @return CustomValue
     */
    protected function find(array $values): CustomValue
    {
        $keyColumn = array_get($this->setting, 'key');
        $record = null;

        if ($keyColumn && !is_nullorempty(array_get($values, $keyColumn))) {
            $record = $this->table->getValueModel()->query()
                ->where(static::queryKey($this->table, $keyColumn), $values[$keyColumn])
                ->orderBy('id', 'desc')
                ->first();
        }

        return $record ?: $this->table->getValueModel();
    }

    /**
     * Raise a record in another table from this one.
     *
     * spawn: {
     *   table: "incident",
     *   when:  {alert_state: "firing", auto_ticket: "yes"},
     *   link:  "alert_id",      column on the new record pointing back here
     *   map:   {short_description: "summary", state: "@new"},
     *   values:{priority: {critical: "1", warning: "3"}}
     * }
     *
     * The link column is also how a repeat is recognised: an alert that fires
     * again while its incident is still open must not open a second one.
     *
     * @param CustomValue $record
     * @return array<string, mixed>|null
     */
    protected function spawn(CustomValue $record)
    {
        $spawn = (array)array_get($this->setting, 'spawn');
        if (empty($spawn) || !$this->matches($record, (array)array_get($spawn, 'when'))) {
            return null;
        }

        $target = CustomTable::getEloquent(array_get($spawn, 'table'));
        $link = array_get($spawn, 'link');
        if (!isset($target) || is_nullorempty($link)) {
            return null;
        }

        $existing = $target->getValueModel()->query()
            ->where(static::queryKey($target, $link), $record->id)
            ->first();
        if ($existing) {
            return ['table' => $target->table_name, 'id' => $existing->id, 'created' => false];
        }

        $values = [$link => $record->id];
        $translate = (array)array_get($spawn, 'values');
        foreach ((array)array_get($spawn, 'map') as $column => $source) {
            $raw = static::isLiteral($source) ? static::literal($source) : $record->getValue($source, false);
            if ($raw instanceof CustomValue) {
                $raw = $raw->id;
            }
            if (is_nullorempty($raw)) {
                continue;
            }
            $values[$column] = array_get($translate, $column . '.' . strval($raw), $raw);
        }

        $child = $target->getValueModel();
        $child->setValue($values);
        $child->save();

        return ['table' => $target->table_name, 'id' => $child->id, 'created' => true];
    }

    /**
     * Finish whatever this record raised.
     *
     * close: {
     *   when:  {alert_state: "resolved"},
     *   table: "incident",
     *   link:  "alert_id",
     *   set:   {state: "resolved"},
     *   skip:  ["closed"]        states already finished, left alone
     * }
     *
     * @param CustomValue $record
     * @return array<int, array<string, mixed>>|null
     */
    protected function close(CustomValue $record)
    {
        $close = (array)array_get($this->setting, 'close');
        if (empty($close) || !$this->matches($record, (array)array_get($close, 'when'))) {
            return null;
        }

        $target = CustomTable::getEloquent(array_get($close, 'table'));
        $link = array_get($close, 'link');
        $set = (array)array_get($close, 'set');
        if (!isset($target) || is_nullorempty($link) || empty($set)) {
            return null;
        }

        $skip = (array)array_get($close, 'skip');
        $children = $target->getValueModel()->query()
            ->where(static::queryKey($target, $link), $record->id)
            ->get();

        $closed = [];
        foreach ($children as $child) {
            $done = false;
            foreach ($set as $column => $_) {
                if (in_array(strval($child->getValue($column, false)), $skip, true)) {
                    $done = true;
                }
            }
            if ($done) {
                continue;
            }

            $values = [];
            foreach ($set as $column => $value) {
                $values[$column] = static::isLiteral($value) ? static::literal($value) : $value;
            }
            $child->setValue($values);
            $child->save();
            $closed[] = ['table' => $target->table_name, 'id' => $child->id];
        }

        return empty($closed) ? null : $closed;
    }

    /**
     * Whether a record satisfies every condition of a rule.
     *
     * @param CustomValue $record
     * @param array<string, mixed> $conditions
     * @return bool
     */
    protected function matches(CustomValue $record, array $conditions): bool
    {
        foreach ($conditions as $column => $expected) {
            $actual = $record->getValue($column, false);
            if ($actual instanceof CustomValue) {
                $actual = $actual->id;
            }
            if (strval($actual) !== strval($expected)) {
                return false;
            }
        }

        return true;
    }

    /**
     * How to search one column: its own database column when it is indexed, the
     * json path otherwise.
     *
     * @param CustomTable $table
     * @param string $columnName
     * @return string
     */
    protected static function queryKey(CustomTable $table, string $columnName): string
    {
        $column = CustomColumn::getEloquent($columnName, $table);
        return isset($column) ? $column->getQueryKey() : 'value->' . $columnName;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    protected static function isLiteral($value): bool
    {
        return is_string($value) && strlen($value) > 1 && $value[0] === '@';
    }

    /**
     * @param string $value
     * @return string
     */
    protected static function literal(string $value): string
    {
        $literal = substr($value, 1);
        return $literal === 'now' ? Carbon::now()->format('Y-m-d H:i:s') : $literal;
    }
}
