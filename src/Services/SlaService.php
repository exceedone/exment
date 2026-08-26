<?php

namespace Exceedone\Exment\Services;

use Carbon\Carbon;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Facades\DB;

/**
 * Turns the SLA policy table into a clock that actually runs.
 *
 * The policies were only ever descriptive: a row saying "P1 incidents must be
 * resolved in 240 minutes" that nothing read. This service reads them, works
 * out each record's deadline, records how much of the clock is gone, and
 * escalates the ones that run out - which is what makes an SLA a control
 * rather than a document.
 *
 * Nothing here names a table. Which tables have an SLA, and which of their
 * columns hold the deadline and the state, is configuration stored on the
 * custom table itself (`custom_tables.options.sla`), so a new table joins in
 * without a code change:
 *
 *   start      business date the clock starts from (falls back to created_at)
 *   clock      column the service owns; seeded from `start`, and moved forward
 *              when a record is escalated so the promotion comes with a fresh
 *              deadline instead of an already-spent one
 *   state      column holding the record's state
 *   open       states the clock runs in - anything else is finished
 *   untouched  states that count as "nobody has picked this up yet"
 *   due        column the resolve deadline is written to
 *   rate       column the percentage of the clock used is written to
 *   stage      column the SLA situation is written to (ok/warn/resp/miss)
 *   flag       column the final met/missed verdict is written to
 *   priority   how to find the policy: {column, map} or {fixed}
 *   escalate   {column, order} - order is worst first, a breach moves one step
 *
 * Business hours come from the policy: 24x7 counts every minute, 8x5 counts
 * only 09:00-18:00 on weekdays. Public holidays are not modelled.
 */
class SlaService
{
    /** Clock is running and comfortable. */
    public const STAGE_OK = 'ok';
    /** Three quarters of the resolve window is gone. */
    public const STAGE_WARN = 'warn';
    /** Nobody has picked it up inside the response window. */
    public const STAGE_RESPONSE = 'resp';
    /** The resolve deadline has passed. */
    public const STAGE_BREACH = 'miss';

    /** Verdict written to the met/missed column. */
    public const FLAG_MET = 'met';
    public const FLAG_MISS = 'miss';

    /** Percentage of the resolve window at which a record starts to warn. */
    public const WARN_PERCENT = 75;

    public const BUSINESS_START_HOUR = 9;
    public const BUSINESS_END_HOUR = 18;

    /** Loop guards. Long enough for a year of business days, short enough to fail loudly. */
    protected const MAX_DAYS = 800;

    /** @var Carbon */
    protected $now;

    /** @var bool write the results back, or only report what would change */
    protected $apply = true;

    /** @var array<int, string> */
    protected $notes = [];

    public function __construct(?Carbon $now = null)
    {
        $this->now = $now ? $now->copy() : Carbon::now();
    }

    /**
     * Messages worth showing the operator after a run.
     *
     * @return array<int, string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * Run the clock over every table that has an SLA setting.
     *
     * @param string|null $tableName limit to one table
     * @param bool $apply false to report without writing
     * @return array<string, array<string, int>> per table counters
     */
    public function run(?string $tableName = null, bool $apply = true): array
    {
        $this->apply = $apply;
        $this->notes = [];

        $policies = $this->loadPolicies();
        if (empty($policies)) {
            $this->notes[] = 'no active SLA policy found';
            return [];
        }

        $results = [];
        $configured = [];
        foreach ($this->slaTables($tableName) as $table) {
            $configured[] = $table->table_name;
            $results[$table->table_name] = $this->runTable($table, array_get($policies, $table->table_name, []));
        }

        // an active policy pointing at a table that has no SLA setting would
        // silently do nothing, which is exactly how the policies ended up
        // decorative in the first place
        if (is_null($tableName)) {
            foreach ($policies as $target => $_) {
                if (!in_array($target, $configured)) {
                    $this->notes[] = "active policy targets \"{$target}\", which has no sla setting - skipped";
                }
            }
        }

        return $results;
    }

    /**
     * Active policies as [target_table][priority] => [response, resolve, hours].
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function loadPolicies(): array
    {
        $table = CustomTable::getEloquent('sla_policy');
        if (!isset($table)) {
            return [];
        }

        $policies = [];
        foreach (DB::table(getDBTableName($table))->whereNull('deleted_at')->get() as $row) {
            $value = json_decode($row->value, true) ?: [];
            if (array_get($value, 'active_flg') !== 'yes') {
                continue;
            }
            $target = array_get($value, 'target_table');
            $priority = array_get($value, 'priority');
            if (is_nullorempty($target) || is_nullorempty($priority)) {
                continue;
            }

            $policies[$target][$priority] = [
                'name' => array_get($value, 'policy_name'),
                'response' => intval(array_get($value, 'response_minutes')),
                'resolve' => intval(array_get($value, 'resolve_minutes')),
                'hours' => array_get($value, 'business_hours') ?: '24x7',
            ];
        }

        return $policies;
    }

    /**
     * Tables carrying an sla setting.
     *
     * @param string|null $tableName
     * @return array<int, CustomTable>
     */
    protected function slaTables(?string $tableName): array
    {
        // deliberately not filterList(): this runs from the scheduler where
        // there is no logged in user to check permissions against
        $query = CustomTable::query();
        if ($tableName) {
            $query->where('table_name', $tableName);
        }

        $tables = [];
        foreach ($query->orderBy('id')->get() as $table) {
            if (is_nullorempty($table->getOption('sla'))) {
                continue;
            }
            $tables[] = $table;
        }

        return $tables;
    }

    /**
     * @param CustomTable $table
     * @param array<string, array<string, mixed>> $policies policies for this table
     * @return array<string, int>
     */
    protected function runTable($table, array $policies): array
    {
        $setting = $table->getOption('sla');
        $counters = ['records' => 0, 'clocked' => 0, 'warned' => 0, 'response' => 0, 'breached' => 0, 'escalated' => 0, 'nopolicy' => 0];

        if (empty($policies)) {
            $this->notes[] = "{$table->table_name}: has an sla setting but no active policy targets it";
            return $counters;
        }

        $dbTable = getDBTableName($table);
        $openStates = (array)array_get($setting, 'open', []);
        $stateColumn = array_get($setting, 'state');

        DB::table($dbTable)->whereNull('deleted_at')->orderBy('id')->chunk(500, function ($rows) use ($table, $dbTable, $setting, $policies, $openStates, $stateColumn, &$counters) {
            foreach ($rows as $row) {
                $counters['records']++;
                $value = json_decode($row->value, true) ?: [];

                $policy = $this->matchPolicy($value, $setting, $policies);
                if (!$policy) {
                    $counters['nopolicy']++;
                    continue;
                }

                $state = $stateColumn ? strval(array_get($value, $stateColumn, '')) : '';
                $isOpen = empty($openStates) || in_array($state, $openStates);

                $start = $this->startedAt($value, $setting, $row);
                $resolveDue = static::addBusinessMinutes($start, $policy['resolve'], $policy['hours']);

                $changes = [];
                $dueColumn = array_get($setting, 'due');
                if ($dueColumn) {
                    $changes[$dueColumn] = $resolveDue->format('Y-m-d H:i:s');
                }

                if (!$isOpen) {
                    // a finished record keeps the verdict it finished with; only
                    // fill in a deadline that was never written
                    if ($dueColumn && !is_nullorempty(array_get($value, $dueColumn))) {
                        unset($changes[$dueColumn]);
                    }
                    $this->write($dbTable, $row->id, $changes);
                    continue;
                }

                $counters['clocked']++;

                $used = static::elapsedBusinessMinutes($start, $this->now, $policy['hours']);
                $rate = $policy['resolve'] > 0 ? intval(round($used * 100 / $policy['resolve'])) : 0;
                $rate = max(0, min(999, $rate));

                $responseDue = static::addBusinessMinutes($start, $policy['response'], $policy['hours']);
                $untouched = in_array($state, (array)array_get($setting, 'untouched', []));

                if ($this->now->gt($resolveDue)) {
                    $stage = static::STAGE_BREACH;
                    $counters['breached']++;
                } elseif ($untouched && $this->now->gt($responseDue)) {
                    $stage = static::STAGE_RESPONSE;
                    $counters['response']++;
                } elseif ($rate >= static::WARN_PERCENT) {
                    $stage = static::STAGE_WARN;
                    $counters['warned']++;
                } else {
                    $stage = static::STAGE_OK;
                }

                $rateColumn = array_get($setting, 'rate');
                $stageColumn = array_get($setting, 'stage');
                if ($rateColumn) {
                    $changes[$rateColumn] = $rate;
                }
                if ($stageColumn) {
                    $changes[$stageColumn] = $stage;
                }
                if ($flagColumn = array_get($setting, 'flag')) {
                    // a breach is a fact about the past: escalation restarts the
                    // clock, but it must not turn a missed SLA back into a met one
                    $alreadyMissed = strval(array_get($value, $flagColumn, '')) == static::FLAG_MISS;
                    $changes[$flagColumn] = ($stage == static::STAGE_BREACH || $alreadyMissed)
                        ? static::FLAG_MISS
                        : static::FLAG_MET;
                }

                // escalate once, on the step into a worse stage. The stage column
                // is what remembers it, so an hourly run does not keep pushing
                // the same record up the priority list.
                $previousStage = $stageColumn ? strval(array_get($value, $stageColumn, '')) : '';
                $worsened = in_array($stage, [static::STAGE_RESPONSE, static::STAGE_BREACH]) && $previousStage != $stage;
                if ($worsened && $this->escalate($value, $setting, $changes)) {
                    $counters['escalated']++;

                    // the promotion comes with a new deadline, counted from now
                    // under the tighter policy. Only the service's own clock
                    // column moves - the date the incident was opened stays put.
                    $clockColumn = array_get($setting, 'clock');
                    if ($clockColumn) {
                        $changes[$clockColumn] = $this->now->format('Y-m-d H:i:s');
                        $promoted = $this->matchPolicy(array_merge($value, $changes), $setting, $policies);
                        if ($promoted) {
                            if ($dueColumn) {
                                $changes[$dueColumn] = static::addBusinessMinutes($this->now, $promoted['resolve'], $promoted['hours'])->format('Y-m-d H:i:s');
                            }
                            if ($rateColumn) {
                                $changes[$rateColumn] = 0;
                            }
                            if ($stageColumn) {
                                $changes[$stageColumn] = $stage;
                            }
                        }
                    }
                }

                $this->write($dbTable, $row->id, $changes);
            }
        });

        return $counters;
    }

    /**
     * Raise the record one step up its priority list. Returns false when there
     * is nowhere left to go, so the counter only counts real moves.
     *
     * @param array<string, mixed> $value
     * @param array<string, mixed> $setting
     * @param array<string, mixed> $changes
     * @return bool
     */
    protected function escalate(array $value, array $setting, array &$changes): bool
    {
        $escalate = array_get($setting, 'escalate');
        $column = array_get($escalate, 'column');
        $order = (array)array_get($escalate, 'order', []);
        if (is_nullorempty($column) || count($order) < 2) {
            return false;
        }

        $current = strval(array_get($value, $column, ''));
        $index = array_search($current, $order, true);
        if ($index === false || $index === 0) {
            return false;
        }

        $changes[$column] = $order[$index - 1];
        return true;
    }

    /**
     * Which policy applies to one record.
     *
     * @param array<string, mixed> $value
     * @param array<string, mixed> $setting
     * @param array<string, array<string, mixed>> $policies
     * @return array<string, mixed>|null
     */
    protected function matchPolicy(array $value, array $setting, array $policies)
    {
        $priority = array_get($setting, 'priority');

        $key = array_get($priority, 'fixed');
        if (is_nullorempty($key)) {
            $column = array_get($priority, 'column');
            $raw = $column ? strval(array_get($value, $column, '')) : '';
            $map = (array)array_get($priority, 'map', []);
            $key = array_get($map, $raw, $raw);
        }

        return is_nullorempty($key) ? null : array_get($policies, $key);
    }

    /**
     * When the clock started for one record.
     *
     * @param array<string, mixed> $value
     * @param array<string, mixed> $setting
     * @param object $row
     * @return Carbon
     */
    protected function startedAt(array $value, array $setting, $row): Carbon
    {
        $raw = null;
        foreach (['clock', 'start'] as $key) {
            $column = array_get($setting, $key);
            $raw = $column ? array_get($value, $column) : null;
            if (!is_nullorempty($raw)) {
                break;
            }
        }
        if (is_nullorempty($raw)) {
            $raw = $row->created_at;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable $e) {
            return Carbon::parse($row->created_at);
        }
    }

    /**
     * Write the computed fields back, skipping records nothing changed on.
     *
     * The clock is written straight to the json rather than through the model:
     * an hourly recalculation is not an edit anybody made, and routing it
     * through the model would bury the real history under a revision per hour.
     *
     * @param string $dbTable
     * @param int $id
     * @param array<string, mixed> $changes
     * @return void
     */
    protected function write(string $dbTable, $id, array $changes)
    {
        if (empty($changes) || !$this->apply) {
            return;
        }

        $pdo = DB::connection()->getPdo();
        $expression = 'value';
        foreach ($changes as $column => $newValue) {
            $expression = 'json_set(' . $expression . ', '
                . $pdo->quote('$."' . $column . '"') . ', '
                . $pdo->quote(strval($newValue)) . ')';
        }

        DB::table($dbTable)->where('id', $id)->update(['value' => DB::raw($expression)]);
    }

    /**
     * Deadline reached by adding working minutes to a starting point.
     *
     * @param Carbon $start
     * @param int $minutes
     * @param string $hours 24x7 or 8x5
     * @return Carbon
     */
    public static function addBusinessMinutes(Carbon $start, int $minutes, string $hours): Carbon
    {
        if ($hours != '8x5') {
            return $start->copy()->addMinutes($minutes);
        }

        $cursor = static::alignToBusiness($start);
        $left = $minutes;
        $guard = 0;
        while ($left > 0 && $guard++ < static::MAX_DAYS) {
            $dayEnd = $cursor->copy()->setTime(static::BUSINESS_END_HOUR, 0, 0);
            $available = intdiv($dayEnd->getTimestamp() - $cursor->getTimestamp(), 60);
            if ($available <= 0) {
                $cursor = static::nextBusinessStart($cursor);
                continue;
            }
            if ($left <= $available) {
                return $cursor->copy()->addMinutes($left);
            }
            $left -= $available;
            $cursor = static::nextBusinessStart($dayEnd);
        }

        return $cursor;
    }

    /**
     * Working minutes between two points.
     *
     * @param Carbon $start
     * @param Carbon $end
     * @param string $hours 24x7 or 8x5
     * @return int
     */
    public static function elapsedBusinessMinutes(Carbon $start, Carbon $end, string $hours): int
    {
        if ($end->lte($start)) {
            return 0;
        }
        if ($hours != '8x5') {
            return intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
        }

        $total = 0;
        $cursor = static::alignToBusiness($start);
        $guard = 0;
        while ($cursor->lt($end) && $guard++ < static::MAX_DAYS) {
            $dayEnd = $cursor->copy()->setTime(static::BUSINESS_END_HOUR, 0, 0);
            $stop = $dayEnd->lt($end) ? $dayEnd : $end;
            $total += max(0, intdiv($stop->getTimestamp() - $cursor->getTimestamp(), 60));
            if ($dayEnd->gte($end)) {
                break;
            }
            $cursor = static::nextBusinessStart($dayEnd);
        }

        return $total;
    }

    /**
     * Move a moment forward to the next instant that counts as working time.
     * A moment already inside the window is returned unchanged.
     *
     * @param Carbon $moment
     * @return Carbon
     */
    protected static function alignToBusiness(Carbon $moment): Carbon
    {
        $cursor = $moment->copy();
        $guard = 0;
        while ($guard++ < static::MAX_DAYS) {
            if ($cursor->isWeekend()) {
                $cursor = $cursor->copy()->addDay()->setTime(static::BUSINESS_START_HOUR, 0, 0);
                continue;
            }
            $open = $cursor->copy()->setTime(static::BUSINESS_START_HOUR, 0, 0);
            $close = $cursor->copy()->setTime(static::BUSINESS_END_HOUR, 0, 0);
            if ($cursor->lt($open)) {
                return $open;
            }
            if ($cursor->gte($close)) {
                $cursor = $cursor->copy()->addDay()->setTime(static::BUSINESS_START_HOUR, 0, 0);
                continue;
            }
            return $cursor;
        }

        return $cursor;
    }

    /**
     * Opening time of the next working day.
     *
     * @param Carbon $moment
     * @return Carbon
     */
    protected static function nextBusinessStart(Carbon $moment): Carbon
    {
        return static::alignToBusiness($moment->copy()->addDay()->setTime(static::BUSINESS_START_HOUR, 0, 0));
    }
}
