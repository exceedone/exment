<?php

namespace Exceedone\Exment\Services\Workflow;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\RelationTable;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowTable;
use Exceedone\Exment\Model\WorkflowTaskRead;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Feature 1: gather the current login user's un-actioned workflow tasks across
 * every workflow-enabled table, and manage the per-user "seen" state (so the
 * navbar icon can show an unseen-count badge, like the notification bell).
 *
 * The navbar polls this every few minutes for EVERY logged-in browser, so the count
 * and the dropdown must never read more than they show:
 *  - countUnseen()      one COUNT per workflow table, no row leaves the database
 *  - topUnseen($limit)  at most $limit rows per table, and only for the tables the
 *                       COUNT already proved are not empty
 *  - getPage($p, $n)    reads id + updated_at only, then loads just the page it shows
 * getTasks() reads everything and is kept for the callers that really do need every task
 * at once (tests, and anything that has to look at the whole list).
 *
 * The screen filter is spelled out as a type once and referred to by name afterwards. It is
 * the one array here whose KEYS are read as code - pendingQuery() branches on 'seen',
 * getPage() on 'sort', the view renders every entry back into its own form - so a bare
 * "array" would hide exactly the part that has to stay in step across the places reading it.
 *
 * @phpstan-type TaskFilter array{custom_table_id: int|null, seen: int|null, from: string|null, to: string|null, q: string|null, sort: string}
 */
class WorkflowTaskService
{
    /**
     * How many tasks the navbar dropdown shows.
     */
    const NAVBAR_ITEM_COUNT = 5;

    /**
     * Longest keyword the search accepts. Anything past this is cut off: the keyword goes
     * into a LIKE over the label columns and nothing useful is that long.
     */
    const MAX_KEYWORD_LENGTH = 128;

    /**
     * Screen filter of this instance, already normalized by normalizeFilter().
     * Every read goes through pendingQuery(), so the two COUNTs, the id reads and the page
     * all see the same conditions - a filter that reached only one of them would show a
     * paginator that does not match its own rows.
     *
     * @var TaskFilter
     */
    private $filter;

    /**
     * @param array<string, mixed> $filter raw or normalized filter; normalizeFilter() is idempotent
     */
    public function __construct(array $filter = [])
    {
        $this->filter = static::normalizeFilter($filter);
    }

    /**
     * Clean the filter coming from the query string.
     *
     * Everything here arrives from the URL, so nothing is trusted: an unknown value becomes
     * "no filter" rather than reaching the query builder. Returns the same shape every time,
     * so the view can read it without isset() checks.
     *
     * @param array<string, mixed> $input
     * @return TaskFilter
     */
    public static function normalizeFilter(array $input): array
    {
        $seen = array_get($input, 'seen');

        return [
            // which workflow table, null = every table the user may see
            'custom_table_id' => self::normalizeId(array_get($input, 'custom_table_id')),
            // 0 = unread only, 1 = read only, null = both
            'seen'            => in_array($seen, ['0', '1', 0, 1], true) ? (int)$seen : null,
            'from'            => self::normalizeDate(array_get($input, 'from')),
            'to'              => self::normalizeDate(array_get($input, 'to')),
            'q'               => self::normalizeKeyword(array_get($input, 'q')),
            // oldest first by default: the oldest un-actioned task is the one holding
            // everybody else up, so it belongs at the top
            'sort'            => array_get($input, 'sort') === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * @param mixed $value
     * @return int|null
     */
    private static function normalizeId($value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) && (int)$value > 0 ? (int)$value : null;
    }

    /**
     * A date, or null. Only a real yyyy-mm-dd survives - "2026-02-31" is not one.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function normalizeDate($value): ?string
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $matches)) {
            return null;
        }

        return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]) ? trim($value) : null;
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private static function normalizeKeyword($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        // a keyword long enough to be a payload rather than a search term is cut, not rejected
        return $value === '' ? null : mb_substr($value, 0, self::MAX_KEYWORD_LENGTH);
    }

    /**
     * The filter as the screen has to render it back into its own form.
     *
     * @return TaskFilter
     */
    public function filter(): array
    {
        return $this->filter;
    }

    /**
     * Is anything filtered at all? Only decides whether the "clear" button is shown - the
     * sort direction is not a filter, it never hides a row.
     *
     * @return bool
     */
    public function isFiltered(): bool
    {
        foreach (['custom_table_id', 'seen', 'from', 'to', 'q'] as $key) {
            if (!is_null($this->filter[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every workflow table the current user may see, for the filter select box.
     * Built without the table filter, otherwise the box would only ever offer the table that
     * is already selected.
     *
     * @return array<int,string> custom_table_id => view name
     */
    public static function tableOptions(): array
    {
        // new self(), not new static(): the constructor is this class's own, and a subclass
        // reaching here through inheritance would be built by a signature it never declared
        return (new self())->workflowCustomTables()
            ->map(function ($custom_table) {
                return $custom_table->table_view_name;
            })
            ->all();
    }

    /**
     * Custom tables with a usable workflow, keyed by id. Resolved once per instance:
     * every public method needs the same list and Workflow::getWorkflowByTable() is not free.
     *
     * @var Collection<int, CustomTable>|null
     */
    private $workflowTables = null;

    /**
     * @var string|null
     */
    private $readTableName = null;

    /**
     * The work-user query of each table, keyed by custom table id. Built once and handed out
     * as a clone: RelationTable::setWorkflowWorkUsersSubQuery() re-reads the workflow actions
     * and the column metadata of the table every time it is called, and one screen needs the
     * same query up to three times (total, page, unseen count).
     *
     * @var array<int,\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>>
     */
    private $baseQueries = [];

    /**
     * Per-table unseen counts, resolved once per instance. The navbar asks for the badge and
     * then for the dropdown items, and the dropdown needs the same counts to know which tables
     * are worth querying at all - without this it would run every COUNT twice.
     * markSeen() drops it, because it changes exactly these numbers.
     *
     * @var array<int,int>|null
     */
    private $unseenCounts = null;

    /**
     * Per-table counts of ALL pending tasks (seen or not), resolved once per instance.
     * The list screen needs the grand total for its paginator and asks for it once per request.
     *
     * @var array<int,int>|null
     */
    private $allCounts = null;

    /**
     * Build the task_key that identifies a single pending task.
     * It only identifies the "seen" state and the read/redirect link, so it holds exactly
     * what workflow_task_reads stores: the record identity. The status is NOT part of it -
     * WorkflowAction::forwardWorkflowValue() deletes the read rows of a record when it moves
     * to another status, which is what makes the task unseen again.
     *
     * @param int|string $customTableId
     * @param int|string $morphId
     * @return string
     */
    public static function taskKey($customTableId, $morphId): string
    {
        return $customTableId . ':' . $morphId;
    }

    /**
     * Shape of a task_key. No leading zeros (ids start at 1, and "07" and "7" must not become
     * two spellings of one task), and it ends with \z, not $ - in PHP "$" also matches just
     * before a trailing newline, so "1:2\n" would slip through.
     */
    private const TASK_KEY_REGEX = '/^([1-9]\d{0,9}):([1-9]\d{0,18})\z/';

    /**
     * Largest value workflow_task_reads.custom_table_id can hold (int unsigned).
     */
    private const MAX_CUSTOM_TABLE_ID = '4294967295';

    /**
     * Split a task_key back into [custom_table_id, morph_id], or null if it is not a task_key.
     * This is the input filter of a GET endpoint that writes to the database, so it also
     * refuses digits that would overflow the columns they are about to be stored in.
     *
     * @param mixed $taskKey
     * @return array{int, int}|null [custom_table_id, morph_id]
     */
    public static function parseTaskKey($taskKey): ?array
    {
        if (!is_string($taskKey) || !preg_match(self::TASK_KEY_REGEX, $taskKey, $matches)) {
            return null;
        }

        // morph_id is bigint unsigned in the database, but it is cast to a PHP int here,
        // so PHP_INT_MAX is the real ceiling.
        if (!self::fitsIn($matches[1], self::MAX_CUSTOM_TABLE_ID) || !self::fitsIn($matches[2], (string)PHP_INT_MAX)) {
            return null;
        }

        return [(int)$matches[1], (int)$matches[2]];
    }

    /**
     * Is the digit string $digits smaller than or equal to $max?
     * Compared as text, because a string of 19 digits can be bigger than PHP_INT_MAX and
     * casting it to int first would silently saturate instead of rejecting it.
     * Both arguments are guaranteed to have no leading zeros.
     *
     * @param string $digits
     * @param string $max
     * @return bool
     */
    private static function fitsIn(string $digits, string $max): bool
    {
        if (strlen($digits) !== strlen($max)) {
            return strlen($digits) < strlen($max);
        }

        return strcmp($digits, $max) <= 0;
    }

    /**
     * Custom tables whose workflow is usable right now, keyed by custom table id.
     *
     * Uses the SAME rule as the rest of the product: Workflow::getWorkflowByTable() checks
     * active_flg, the active period (active_start_date / active_end_date) and
     * setting_completed_flg, and reads from the cache instead of hitting the DB.
     *
     * Tables the user has no access to at all are dropped here, before any query is built.
     * Building one work-user query costs about as much as running it (it reads the workflow
     * actions and the indexed columns of the table), and the permission scope would turn every
     * one of them into "where id < 0" anyway.
     *
     * @return Collection<int, CustomTable>
     */
    private function workflowCustomTables(): Collection
    {
        if (isset($this->workflowTables)) {
            return $this->workflowTables;
        }

        if (!$this->hasBaseUser()) {
            return $this->workflowTables = collect();
        }

        // allRecordsCache() is declared as "collection OR one record OR null" (the shared
        // trait serves both shapes), so the collection methods below sit on a union type.
        // This says which of the two it is here - it is always called for the whole list.
        /** @var Collection<int, WorkflowTable> $workflowTables */
        $workflowTables = WorkflowTable::allRecordsCache();

        // the map() below can produce null and the filter() after it drops exactly those,
        // which is a guarantee the analyser cannot read out of a closure
        /** @var Collection<int, CustomTable> $tables */
        $tables = $workflowTables
            ->pluck('custom_table_id')
            ->unique()
            ->filter(function ($customTableId) {
                return !is_nullorempty(Workflow::getWorkflowByTable($customTableId));
            })
            ->map(function ($customTableId) {
                return CustomTable::getEloquent($customTableId);
            })
            ->filter(function ($custom_table) {
                if (is_nullorempty($custom_table) || !$this->hasAnyAccess($custom_table)) {
                    return false;
                }

                // filtering here and not in every loop: a table that is filtered out is never
                // counted, never read and never opened - it costs nothing at all
                $only = $this->filter['custom_table_id'];

                return is_null($only) || $custom_table->id == $only;
            })
            ->keyBy('id');

        return $this->workflowTables = $tables;
    }

    /**
     * Could the current user see ANY record of this table?
     *
     * This is the negation of the last branch of CustomValueModelScope, the one that ends in
     * "where id < 0". It answers "is it worth querying this table at all", not "which records" -
     * the scope still runs on every query and remains the only thing that decides that.
     *
     * Deliberately errs towards true: a wrong true costs one COUNT that returns 0, a wrong
     * false would hide a real task. Hence the system tables (the scope filters those by a
     * different rule) and the ACCESS-instead-of-ALL check on the parent table.
     *
     * @param CustomTable $custom_table
     * @return bool
     */
    private function hasAnyAccess(CustomTable $custom_table): bool
    {
        $user = \Exment::user();
        if (!isset($user)) {
            return false;
        }
        if ($user->isAdministrator()) {
            return true;
        }

        // the scope filters these by organization / login user, not by table permission
        if (in_array($custom_table->table_name, [SystemTableName::USER, SystemTableName::ORGANIZATION, SystemTableName::DOCUMENT])) {
            return true;
        }

        if ($custom_table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return true;
        }

        // a 1:N child can be readable through its parent alone
        if (boolval($custom_table->getOption('inherit_parent_permission'))) {
            $relation = CustomRelation::getRelationByChild($custom_table, RelationType::ONE_TO_MANY);
            $parent_table = !is_nullorempty($relation) ? $relation->parent_custom_table : null;
            if (isset($parent_table) && $parent_table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is there a login user WITH the user record the work-user query needs?
     *
     * They can come apart: the user record is soft deleted when somebody leaves the company,
     * but login_users keeps its row. That account can still sign in, and the work-user
     * conditions then read \Exment::user()->base_user->belong_organizations on a null
     * base_user - a fatal error. The navbar runs on EVERY admin page, so without this guard
     * one leftover login row white-screens the whole back office for that account.
     *
     * No base user also means no organization and no user id, so this account cannot be the
     * work user of anything: an empty task list is the right answer, not just the safe one.
     *
     * @return bool
     */
    private function hasBaseUser(): bool
    {
        $user = \Exment::user();

        return isset($user) && isset($user->base_user) && !is_nullorempty(\Exment::getUserId());
    }

    /**
     * @return string
     */
    private function readTableName(): string
    {
        if (isset($this->readTableName)) {
            return $this->readTableName;
        }

        return $this->readTableName = (new WorkflowTaskRead())->getTable();
    }

    /**
     * Records of $custom_table the current login user still has to act on.
     *
     * $onlyUnseen adds the "not read yet" filter as SQL. It is a NOT EXISTS on
     * (target_user_id, custom_table_id, morph_id) - exactly the leading columns of the unique
     * index of workflow_task_reads - so the badge can be a plain COUNT and no pending row ever
     * has to be fetched, hydrated and thrown away just to be counted.
     *
     * @param CustomTable $custom_table
     * @param bool $onlyUnseen
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    private function pendingQuery(CustomTable $custom_table, bool $onlyUnseen)
    {
        $tableName = getDBTableName($custom_table);

        // clone: the memo must stay untouched, every caller adds its own select/order/limit.
        // The scopes are applied at execution time, so cloning before that changes nothing.
        $query = clone $this->baseQuery($custom_table);

        // $onlyUnseen is what the caller needs (badge, navbar); the screen filter can ask for
        // either side. Asking for both at once is not a contradiction to guard against - it is
        // how "how many unread rows does this read-only filter have" correctly answers zero.
        if ($onlyUnseen || $this->filter['seen'] === 0) {
            $query->whereNotExists($this->seenSubQuery($custom_table, $tableName));
        }
        if ($this->filter['seen'] === 1) {
            $query->whereExists($this->seenSubQuery($custom_table, $tableName));
        }

        $this->applyFilter($query, $custom_table, $tableName);

        return $query;
    }

    /**
     * "The current user has already opened this record" as a correlated sub query.
     *
     * The conditions are in the order (target_user_id, custom_table_id, morph_id) - exactly the
     * leading columns of the unique index of workflow_task_reads.
     *
     * @param CustomTable $custom_table
     * @param string $tableName
     * @return \Closure
     */
    private function seenSubQuery(CustomTable $custom_table, string $tableName): \Closure
    {
        $readTable = $this->readTableName();
        $userId = \Exment::getUserId();

        return function ($sub) use ($readTable, $tableName, $custom_table, $userId) {
            $sub->selectRaw('1')
                ->from($readTable)
                ->where($readTable . '.target_user_id', $userId)
                ->where($readTable . '.custom_table_id', $custom_table->id)
                ->whereColumn($readTable . '.morph_id', $tableName . '.id');
        };
    }

    /**
     * Add the date range and the keyword of the screen filter.
     *
     * @param \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query
     * @param CustomTable $custom_table
     * @param string $tableName
     * @return void
     */
    private function applyFilter($query, CustomTable $custom_table, string $tableName): void
    {
        // the column is compared against a plain string instead of whereDate(): whereDate()
        // wraps the column in date(), which throws away any chance of using an index on it
        if (!is_null($this->filter['from'])) {
            $query->where($tableName . '.updated_at', '>=', $this->filter['from'] . ' 00:00:00');
        }
        if (!is_null($this->filter['to'])) {
            // the end date is inclusive, so the bound is the start of the following day
            $query->where(
                $tableName . '.updated_at',
                '<',
                \Carbon\Carbon::parse($this->filter['to'])->addDay()->format('Y-m-d') . ' 00:00:00'
            );
        }

        if (is_null($this->filter['q'])) {
            return;
        }

        $columns = $this->searchColumns($custom_table);
        $keyword = $this->filter['q'];
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword) . '%';

        $query->where(function ($sub) use ($columns, $like, $keyword, $tableName) {
            foreach ($columns as $column) {
                // getQueryKey() is the generated, indexed column when the column is indexed,
                // and the json path otherwise - the same key Exment filters on everywhere else
                $sub->orWhere($tableName . '.' . $column->getQueryKey(), 'LIKE', $like);
            }

            // the label starts with "#<id>" on tables that show it, so "#590" and "590" are
            // both things a user will type to find one record
            $id = ltrim($keyword, '#');
            if ($id !== '' && ctype_digit($id) && self::fitsIn($id, (string)PHP_INT_MAX)) {
                $sub->orWhere($tableName . '.id', (int)$id);
            }
        });
    }

    /**
     * The columns the keyword search looks at: the ones that build the label printed in the
     * "data" column, so that searching matches what is on the screen.
     *
     * Mirrors CustomValue::getBasicLabel(), including its fallback to the first column.
     *
     * @param CustomTable $custom_table
     * @return Collection<int, CustomColumn>
     */
    private function searchColumns(CustomTable $custom_table): Collection
    {
        $label_columns = $custom_table->getLabelColumns();

        // expert mode stores a format string instead of column records; there is no single
        // column to match then, so fall back to the first column like getBasicLabel() does
        if (is_string($label_columns) || is_nullorempty($label_columns) || count($label_columns) == 0) {
            $first = $custom_table->custom_columns_cache->first();

            // the ternary is the null guard; is_nullorempty() is a plain call, so the type
            // of $first does not narrow through it
            /** @var Collection<int, CustomColumn> $fallback */
            $fallback = collect(is_nullorempty($first) ? [] : [$first]);

            return $fallback;
        }

        // same shape as workflowCustomTables(): map() may yield null, filter() removes it
        /** @var Collection<int, CustomColumn> $columns */
        $columns = collect($label_columns)->map(function ($label_column) {
            return CustomColumn::getEloquent($label_column->table_label_id);
        })->filter(function ($custom_column) {
            return !is_nullorempty($custom_column);
        })->values();

        return $columns;
    }

    /**
     * The "records the current user must act on" query of one table, built at most once per
     * instance. The conditions depend on the login user, and one instance only ever serves
     * one request, so the query is the same every time it is asked for.
     *
     * @param CustomTable $custom_table
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    private function baseQuery(CustomTable $custom_table)
    {
        // the property is keyed by int; an Eloquent attribute carries no type here
        $customTableId = (int)$custom_table->id;

        if (isset($this->baseQueries[$customTableId])) {
            return $this->baseQueries[$customTableId];
        }

        $modelName = getModelName($custom_table->table_name);

        // $modelName::query() keeps CustomValueModelScope, the permission filter. The class
        // name is in a variable, so the type of what comes back is only known here - and the
        // property it is about to be stored in is a typed one.
        /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query */
        $query = $modelName::query();
        // reuse the existing, tested "records the current user must act on" logic
        RelationTable::setWorkflowWorkUsersSubQuery($query, $custom_table, false);

        return $this->baseQueries[$customTableId] = $query;
    }

    /**
     * One row of the task list / dropdown.
     *
     * @param CustomTable $custom_table
     * @param \Exceedone\Exment\Model\CustomValue $value
     * @return array<string, mixed> one row of the task list
     */
    private function taskRow(CustomTable $custom_table, $value): array
    {
        return [
            'custom_table_id'   => $custom_table->id,
            'table_view_name'   => $custom_table->table_view_name,
            'icon'              => $custom_table->getOption('icon') ?: 'fa-tasks',
            'color'             => $custom_table->getOption('color') ?: null,
            'morph_id'          => $value->id,
            'label'             => $value->getLabel(),
            'url'               => $value->getUrl(),
            'status_name'       => $value->workflow_status_name,
            'status_tag'        => $value->workflow_status_tag,
            'updated_at'        => $value->updated_at,
            'task_key'          => static::taskKey($custom_table->id, $value->id),
        ];
    }

    /**
     * Number of un-actioned, not yet seen tasks, per custom table.
     * One COUNT per workflow table - N is the number of tables that HAVE a workflow
     * (metadata, a handful of them), never the number of records.
     *
     * @return array<int,int> custom_table_id => count. Tables with nothing pending are dropped.
     */
    private function countUnseenPerTable(): array
    {
        if (isset($this->unseenCounts)) {
            return $this->unseenCounts;
        }

        // "read only": pendingQuery() would put whereExists AND whereNotExists on the same
        // rows, so every count is zero by construction. No table has to be touched to say so.
        if ($this->filter['seen'] === 1) {
            return $this->unseenCounts = [];
        }

        // "unread only": pendingQuery() already carries the very same whereNotExists whether
        // it is asked for all or for unseen, so the two counts are the same number - and the
        // list screen asks for both. Measured on a 53,000 task list: one full scan per
        // workflow table saved, ~420 ms.
        if ($this->filter['seen'] === 0 && isset($this->allCounts)) {
            return $this->unseenCounts = $this->allCounts;
        }

        $counts = [];

        foreach ($this->workflowCustomTables() as $customTableId => $custom_table) {
            $count = $this->pendingQuery($custom_table, true)
                ->distinct()
                ->count(getDBTableName($custom_table) . '.id');

            if ($count > 0) {
                $counts[$customTableId] = $count;
            }
        }

        return $this->unseenCounts = $counts;
    }

    /**
     * Number of pending tasks the current user has NOT seen yet.
     */
    public function countUnseen(): int
    {
        return array_sum($this->countUnseenPerTable());
    }

    /**
     * Number of un-actioned tasks per custom table, seen or not.
     * One COUNT per workflow table, so the list screen can show a grand total without any row
     * leaving the database.
     *
     * @return array<int,int> custom_table_id => count. Tables with nothing pending are dropped.
     */
    private function countAllPerTable(): array
    {
        if (isset($this->allCounts)) {
            return $this->allCounts;
        }

        // the mirror of countUnseenPerTable(): under "unread only" both readers run the very
        // same query, so whichever ran first answers for the other one too
        if ($this->filter['seen'] === 0 && isset($this->unseenCounts)) {
            return $this->allCounts = $this->unseenCounts;
        }

        $counts = [];

        foreach ($this->workflowCustomTables() as $customTableId => $custom_table) {
            $count = $this->pendingQuery($custom_table, false)
                ->distinct()
                ->count(getDBTableName($custom_table) . '.id');

            if ($count > 0) {
                $counts[$customTableId] = $count;
            }
        }

        return $this->allCounts = $counts;
    }

    /**
     * Total number of un-actioned tasks of the current login user, seen or not.
     */
    public function countAll(): int
    {
        return array_sum($this->countAllPerTable());
    }

    /**
     * The newest $limit un-actioned tasks, seen or not - this is what the navbar dropdown lists.
     *
     * The dropdown lists TASKS, not unread marks. "There is no un-actioned task"
     * (workflow_task.empty) is the very sentence the list screen prints under an empty table, so
     * it may only appear when there is really nothing left to act on. Pressing "mark all as seen"
     * empties the badge; the tasks themselves have not gone anywhere.
     *
     * @param int $limit
     * @return Collection<int, array<string, mixed>>
     */
    public function topPending(int $limit = self::NAVBAR_ITEM_COUNT): Collection
    {
        // every workflow table has to be asked: a table with no UNSEEN task can still hold
        // pending ones, so the unseen COUNTs cannot narrow this down
        return $this->topRows($limit, false, $this->workflowCustomTables()->keys()->all());
    }

    /**
     * The newest $limit not-yet-seen tasks.
     *
     * @param int $limit
     * @return Collection<int, array<string, mixed>>
     */
    public function topUnseen(int $limit = self::NAVBAR_ITEM_COUNT): Collection
    {
        // the COUNTs the badge needs anyway already say which tables are worth reading
        return $this->topRows($limit, true, array_keys($this->countUnseenPerTable()));
    }

    /**
     * The newest $limit tasks of the given tables.
     *
     * This is the hot path - every open browser calls it on a timer - so it reads as little as
     * it can: each table returns at most $limit id + updated_at pairs, and a model is built only
     * for the $limit rows that end up on screen (not for $limit rows PER table).
     *
     * @param int $limit
     * @param bool $onlyUnseen
     * @param array<int> $customTableIds
     * @return Collection<int, array<string, mixed>>
     */
    private function topRows(int $limit, bool $onlyUnseen, array $customTableIds): Collection
    {
        if ($limit < 1) {
            return collect();
        }

        $tables = $this->workflowCustomTables();
        $index = [];

        foreach ($customTableIds as $customTableId) {
            $custom_table = $tables->get($customTableId);
            if (is_nullorempty($custom_table)) {
                continue;
            }

            $tableName = getDBTableName($custom_table);

            $rows = $this->pendingQuery($custom_table, $onlyUnseen)
                ->distinct()
                ->select([$tableName . '.id', $tableName . '.updated_at'])
                ->orderBy($tableName . '.updated_at', 'desc')
                ->orderBy($tableName . '.id', 'desc')
                ->limit($limit)
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                $index[] = self::indexEntry($customTableId, $row->id, $row->updated_at);
            }
        }

        // the dropdown always shows the newest, whatever the list screen is sorted by
        self::sortIndex($index, 'desc');

        return $this->buildRows(array_map([self::class, 'indexItem'], array_slice($index, 0, $limit)));
    }

    /**
     * Gather ALL un-actioned workflow tasks of the current login user.
     * Only the full list screen needs this: it sorts and paginates across tables, so it
     * cannot limit per table. The navbar must use countUnseen() / topUnseen() instead.
     *
     * @return Collection<int, array<string, mixed>> each item is a task row (incl. task_key)
     */
    public function getTasks(): Collection
    {
        $rows = collect();

        foreach ($this->workflowCustomTables() as $custom_table) {
            $tableName = getDBTableName($custom_table);

            $values = $this->pendingQuery($custom_table, false)
                ->with(['workflow_value'])
                ->select($tableName . '.*')
                ->distinct()
                ->get();

            // the query is built from getModelName(), whose class is only known at runtime, so
            // the rows arrive typed as the base Model; every custom value table extends CustomValue
            /** @var \Exceedone\Exment\Model\CustomValue $value */
            foreach ($values as $value) {
                $rows->push($this->taskRow($custom_table, $value));
            }
        }

        return $rows->sortByDesc('updated_at')->values();
    }

    /**
     * One page of the task list, newest first across every workflow table.
     *
     * The screen shows $perPage rows, so only $perPage records are turned into a model.
     * Sorting across tables cannot be one SQL statement (each custom table is a physical table
     * of its own), so it is done in two steps:
     *  1. per table, read id + updated_at of its newest ($page * $perPage) pending records.
     *     That is enough AND exact: a record that is not among the newest N of its own table
     *     can never be among the newest N of all tables together.
     *  2. merge, cut out the page, and load only the records of that page.
     * The grand total is countAll(), one COUNT per table - no row is read for it.
     *
     * Like every offset pagination this reads more as the page number grows (rule: LIMIT).
     * It is bounded by the user's own pending tasks, and the page is clamped to the last
     * page that actually exists.
     *
     * @param int $page 1-based
     * @param int $perPage
     * @return array{rows: Collection<int, array<string, mixed>>, total: int, page: int}
     */
    public function getPage(int $page, int $perPage): array
    {
        $perPage = max(1, $perPage);
        $total = $this->countAll();

        // clamp: "?page=999999" must not turn into "read every id of every table"
        $lastPage = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);

        $head = $page * $perPage;
        $index = [];
        $direction = $this->filter['sort'];
        $tables = $this->workflowCustomTables();

        // countAll() above already asked every table how many pending records it holds, and
        // dropped the ones that hold none. Reading those again would be a full work-user scan
        // per table to fetch nothing: the head read walks the counted tables only, and asks
        // each for no more rows than it actually has.
        foreach ($this->countAllPerTable() as $customTableId => $tableTotal) {
            $custom_table = $tables->get($customTableId);
            if (is_nullorempty($custom_table)) {
                continue;
            }

            $tableName = getDBTableName($custom_table);

            // toBase(): Builder::toBase() applies the scopes first, so the permission filter is
            // still there - but no model is hydrated. These rows only decide WHICH records the
            // page contains. Both ordered columns are in the select list, as DISTINCT requires.
            $rows = $this->pendingQuery($custom_table, false)
                ->distinct()
                ->select([$tableName . '.id', $tableName . '.updated_at'])
                ->orderBy($tableName . '.updated_at', $direction)
                ->orderBy($tableName . '.id', $direction)
                // min(): SELECT DISTINCT id, updated_at returns one row per distinct id
                // (updated_at belongs to the row), which is exactly what countAll() counted
                ->limit(min($head, $tableTotal))
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                $index[] = self::indexEntry($customTableId, $row->id, $row->updated_at);
            }
        }

        self::sortIndex($index, $direction);

        return [
            'rows'  => $this->buildRows(array_map([self::class, 'indexItem'], array_slice($index, ($page - 1) * $perPage, $perPage))),
            'total' => $total,
            'page'  => $page,
        ];
    }

    /**
     * One entry of the id index getPage() / topRows() sort across tables, packed into a
     * single string: updated_at, morph id, custom table id - fixed width, zero padded, so
     * byte order IS the sort order (updated_at first, id as tie breaker, table id last).
     *
     * Packed on purpose: the index can carry the id of every pending task the user has (the
     * last page of an offset pagination reads them all), and one short string instead of a
     * three-field array is the difference between a few and a few dozen megabytes there
     * (measured at 53,000 tasks: 34 MB of arrays, ~6 MB packed). It also turns the sort into
     * plain sort()/rsort() - no PHP comparator called half a million times.
     *
     * The table id column is what makes the order TOTAL: record ids are only unique inside
     * one table, and two workflow tables happily hold the same id with the same timestamp.
     * Without a total order two such records could show up on both page 1 and page 2, or
     * on neither.
     *
     * @param int|string $customTableId
     * @param int|string $morphId
     * @param mixed $updatedAt "Y-m-d H:i:s" from the database; NULL becomes spaces, which
     *                         sort before every real date - the end MySQL puts NULLs at, so
     *                         the PHP merge and the SQL ORDER BY agree
     * @return string
     */
    private static function indexEntry($customTableId, $morphId, $updatedAt): string
    {
        return sprintf('%s|%019d|%010d', str_pad((string)$updatedAt, 19), $morphId, $customTableId);
    }

    /**
     * The {custom_table_id, id} of one packed index entry - the shape buildRows() reads.
     *
     * @param string $entry
     * @return array{custom_table_id: int, id: int}
     */
    private static function indexItem(string $entry): array
    {
        [, $morphId, $customTableId] = explode('|', $entry);

        return ['custom_table_id' => (int)$customTableId, 'id' => (int)$morphId];
    }

    /**
     * Sort a packed id index (see indexEntry()).
     *
     * The direction orders the whole entry, and so must the per-table ORDER BY that produced
     * the index: page 3 of an ascending list is built from the OLDEST rows of every table, and
     * a table that returned its newest ones instead would simply not have them.
     *
     * @param array<string> $index
     * @param string $direction 'asc' or 'desc'
     * @return void
     */
    private static function sortIndex(array &$index, string $direction = 'desc'): void
    {
        if ($direction === 'asc') {
            sort($index, SORT_STRING);
        } else {
            rsort($index, SORT_STRING);
        }
    }

    /**
     * Turn "which records" into the rows the screen renders: one query per custom table
     * whatever the number of records, plus one query for the seen state of exactly these rows.
     *
     * @param array<int, array{custom_table_id: int, id: int}> $slice already in display order
     * @return Collection<int, array<string, mixed>>
     */
    private function buildRows(array $slice): Collection
    {
        if (empty($slice)) {
            return collect();
        }

        $byTable = [];
        foreach ($slice as $item) {
            $byTable[$item['custom_table_id']][] = $item['id'];
        }

        $tables = $this->workflowCustomTables();
        $seen = $this->seenSet($byTable);

        $built = [];
        foreach ($byTable as $customTableId => $ids) {
            $custom_table = $tables->get($customTableId);
            if (is_nullorempty($custom_table)) {
                continue;
            }

            $tableName = getDBTableName($custom_table);

            // the permission scope runs again here; the ids already came from a query that had
            // it, so this is only defence in depth and costs nothing
            $values = getModelName($custom_table->table_name)::query()
                ->with(['workflow_value'])
                ->whereIn($tableName . '.id', $ids)
                ->get();

            foreach ($values as $value) {
                $row = $this->taskRow($custom_table, $value);
                $row['seen'] = isset($seen[$row['task_key']]);
                // enableDelete() is the same check CustomValueController runs before deleting:
                // permission, disabled form action, one-record tables, workflow data lock and
                // the parent record. Asking it here only decides whether the button is drawn -
                // the request itself is checked again by that controller.
                $row['can_delete'] = $value->enableDelete(true) === true;
                // The delete endpoint takes a comma separated id list, but an id only means
                // something inside one table, so a multi-row delete has to be grouped by table.
                // getUrl(['list' => true]) returns the very base $row['url'] is built on, so the
                // batch and the single delete can never end up pointing at different endpoints.
                $row['table_url'] = $value->getUrl(['list' => true]);
                $built[$row['task_key']] = $row;
            }
        }

        // replay the order decided in getPage(); $built is keyed, so it has no order of its own
        $rows = collect();
        foreach ($slice as $item) {
            $key = static::taskKey($item['custom_table_id'], $item['id']);
            if (isset($built[$key])) {
                $rows->push($built[$key]);
            }
        }

        return $rows;
    }

    /**
     * EVERY task key the current login user has already seen.
     *
     * Reads that user's whole history, which only ever grows, so no screen calls it: it is kept
     * for diagnostics and for the tests that assert the stored state directly. The request path
     * uses seenSet(), which asks only about the rows it is about to display.
     *
     * @return array<string>
     */
    public function seenKeys(): array
    {
        return WorkflowTaskRead::where('target_user_id', \Exment::getUserId())
            ->select(['custom_table_id', 'morph_id'])
            ->get()
            ->map(function ($read) {
                return static::taskKey($read->custom_table_id, $read->morph_id);
            })
            ->all();
    }

    /**
     * Which of THESE records the current login user has already seen.
     *
     * Bounded by the rows being displayed instead of by how long the account has existed.
     * The filter is one OR-group per custom table on (target_user_id, custom_table_id,
     * morph_id) - the leading columns of the unique index of workflow_task_reads.
     *
     * @param array<int,array<int>> $byTable custom_table_id => morph ids
     * @return array<string,bool> task_key => true
     */
    private function seenSet(array $byTable): array
    {
        if (empty($byTable) || !$this->hasBaseUser()) {
            return [];
        }

        $userId = \Exment::getUserId();
        $seen = [];

        foreach (self::idChunks($byTable) as $group) {
            $rows = WorkflowTaskRead::where('target_user_id', $userId)
                ->where(function ($query) use ($group) {
                    foreach ($group as $customTableId => $morphIds) {
                        $query->orWhere(function ($query) use ($customTableId, $morphIds) {
                            $query->where('custom_table_id', $customTableId)
                                ->whereIn('morph_id', $morphIds);
                        });
                    }
                })
                ->select(['custom_table_id', 'morph_id'])
                ->get();

            foreach ($rows as $row) {
                $seen[static::taskKey($row->custom_table_id, $row->morph_id)] = true;
            }
        }

        return $seen;
    }

    /**
     * Split a "custom_table_id => morph ids" map into groups of at most $size ids in total, so
     * one statement never carries an unbounded IN list - markAllSeen() can hand over every
     * pending record of the installation.
     *
     * @param array<int,array<int>> $byTable
     * @param int $size
     * @return array<int,array<int,array<int>>>
     */
    private static function idChunks(array $byTable, int $size = 1000): array
    {
        $groups = [];
        $current = [];
        $count = 0;

        foreach ($byTable as $customTableId => $morphIds) {
            // max(1, ...): array_chunk() throws on a size below 1, and the only thing standing
            // between a caller and that is the default value of this parameter
            foreach (array_chunk(array_values($morphIds), max(1, $size)) as $chunk) {
                $current[$customTableId] = $chunk;
                $count += count($chunk);

                if ($count >= $size) {
                    $groups[] = $current;
                    $current = [];
                    $count = 0;
                }
            }
        }

        if (!empty($current)) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * Gather tasks and annotate each with a "seen" flag.
     *
     * The seen lookup covers exactly the tasks just gathered, so it stays proportional to the
     * list and not to the user's whole reading history.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getTasksWithSeen(): Collection
    {
        $tasks = $this->getTasks();

        $byTable = [];
        foreach ($tasks as $task) {
            // seenSet() is keyed by int on both levels; a task row is a plain array, so the
            // two ids arrive here without a type of their own
            $byTable[(int)$task['custom_table_id']][] = (int)$task['morph_id'];
        }

        $seen = $this->seenSet($byTable);

        /** @var Collection<int, array<string, mixed>> $withSeen */
        $withSeen = $tasks->map(function ($row) use ($seen) {
            $row['seen'] = isset($seen[$row['task_key']]);
            return $row;
        });

        return $withSeen;
    }

    /**
     * Cache key of this user's navbar payload; ApiController::workflowTaskPage() caches under
     * it for one poll interval. The key carries a per-user VERSION that navbarCacheForget()
     * bumps on every own write, so invalidation never has to know which locale variants were
     * cached - old versions simply become unreachable and expire with their TTL.
     *
     * @return string|null null when there is no user record to key on
     */
    public static function navbarCacheKey(): ?string
    {
        $userId = \Exment::getUserId();
        if (is_nullorempty($userId)) {
            return null;
        }

        $version = (int)\Cache::get('exment_workflow_task_nav_ver_' . $userId, 0);

        return 'exment_workflow_task_nav_' . $userId . '_' . $version . '_' . app()->getLocale();
    }

    /**
     * Make the CURRENT user's next navbar poll recompute instead of answering from the cache.
     *
     * Called wherever this user's own view of the list changes: marking or unmarking here,
     * acting on a workflow (WorkflowAction::forwardWorkflowValue) and deleting a record
     * (CustomValue). What OTHER users change reaches a cached badge when the cache expires -
     * within one poll interval, the same delay polling itself already has.
     *
     * @return void
     */
    public static function navbarCacheForget(): void
    {
        $userId = \Exment::getUserId();
        if (is_nullorempty($userId)) {
            return;
        }

        try {
            \Cache::increment('exment_workflow_task_nav_ver_' . $userId);
        } catch (\Throwable $ex) {
            // a cache backend refusing the write must never break the action being performed
        }
    }

    /**
     * Mark the given task keys as seen for the current login user (idempotent).
     *
     * Two queries in total, whatever the number of keys: one SELECT for what is already
     * stored and one bulk INSERT for the rest. firstOrCreate() per key would be two
     * queries per task, inside a foreach.
     *
     * @param array<string> $taskKeys
     * @return void
     */
    public function markSeen(array $taskKeys): void
    {
        // whatever happens below, the memoised counts are no longer trustworthy -
        // and neither is the navbar payload cached for this user. Both counts go: under
        // "unread only" the all-count is a count of unseen rows too, so this changes it.
        $this->unseenCounts = null;
        $this->allCounts = null;
        static::navbarCacheForget();

        // no user record to own the mark - target_user_id is NOT NULL, so writing would throw
        if (!$this->hasBaseUser()) {
            return;
        }

        $userId = \Exment::getUserId();

        // parse everything first: a malformed key is skipped, it never aborts the batch
        $byTable = [];
        foreach (array_unique($taskKeys) as $taskKey) {
            $parsed = static::parseTaskKey($taskKey);
            if (is_nullorempty($parsed)) {
                continue;
            }
            $byTable[$parsed[0]][$parsed[1]] = $parsed[1];
        }

        if (empty($byTable)) {
            return;
        }

        // which of these are already stored: one SELECT per 1000 ids, keyed by task_key -
        // the same "{custom_table_id}:{morph_id}" string the loop below builds
        $stored = $this->seenSet($byTable);

        $now = \Carbon\Carbon::now();
        $inserts = [];
        foreach ($byTable as $customTableId => $morphIds) {
            foreach ($morphIds as $morphId) {
                if (isset($stored[$customTableId . ':' . $morphId])) {
                    continue;
                }
                $inserts[] = [
                    'target_user_id'  => $userId,
                    'custom_table_id' => $customTableId,
                    'morph_id'        => $morphId,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                    'created_user_id' => $userId,
                    'updated_user_id' => $userId,
                ];
            }
        }

        if (empty($inserts)) {
            return;
        }

        // chunked: markAllSeen() can hand over every pending record of the installation, and a
        // single INSERT carrying tens of thousands of rows would exceed max_allowed_packet -
        // which fails the whole statement, so nothing at all would be marked as seen.
        foreach (array_chunk($inserts, 1000) as $chunk) {
            try {
                WorkflowTaskRead::insert($chunk);
            } catch (QueryException $ex) {
                // another tab of the same user inserted one of these rows between the SELECT and
                // the INSERT and hit the unique index. Rare, and marking a task as seen must never
                // become a 500, so fall back to the slow but conflict-free path for this chunk.
                foreach ($chunk as $insert) {
                    WorkflowTaskRead::firstOrCreate([
                        'target_user_id'  => $insert['target_user_id'],
                        'custom_table_id' => $insert['custom_table_id'],
                        'morph_id'        => $insert['morph_id'],
                    ]);
                }
            }
        }
    }

    /**
     * Mark every currently-pending task as seen for the current login user.
     *
     * @return void
     */
    public function markAllSeen(): void
    {
        $this->markSeen($this->pendingKeys());
    }

    /**
     * Mark the SELECTED tasks as seen (the batch action of the list screen).
     *
     * The keys come straight from the browser, so they are not trusted. markSeen() stores any
     * well formed key, which would let any logged-in user fill workflow_task_reads with rows
     * for records they cannot even see - the same hole workflow_task/read closes by resolving
     * the record first. Here the guard is pendingQuery() itself: the submitted ids are checked
     * against the permission scoped work-user query of their own table, restricted to exactly
     * these ids, so a key that is not this user's task or not visible to them never comes back.
     *
     * The check asks for UNSEEN tasks only, so re-marking something already seen counts as
     * nothing to do - which is exactly the answer notify_navbar's rowCheck gives for rows
     * that are already read.
     *
     * Cost: one indexed id-only query per table THE SELECTION touches, over the submitted ids
     * alone - a screen page or two. Asking pendingKeys() here instead would read the id of
     * every pending task the user has: measured at 53,000 tasks, that made every 100-row click
     * pay half a second and megabytes of key strings for the same answer.
     *
     * @param array<string> $taskKeys
     * @return int how many tasks were actually marked
     */
    public function markSeenSelected(array $taskKeys): int
    {
        // nothing selected: answer without asking the database at all - this is an endpoint
        // every logged-in user can call.
        if (empty($taskKeys)) {
            return 0;
        }

        // parse everything first: a malformed key is skipped, it never aborts the batch
        $byTable = [];
        foreach (array_unique($taskKeys) as $taskKey) {
            $parsed = static::parseTaskKey($taskKey);
            if (is_nullorempty($parsed)) {
                continue;
            }
            $byTable[$parsed[0]][$parsed[1]] = $parsed[1];
        }

        $tables = $this->workflowCustomTables();
        $keys = [];

        foreach ($byTable as $customTableId => $morphIds) {
            // not a workflow table this user may see at all: every one of its keys is
            // dropped without a query
            $custom_table = $tables->get($customTableId);
            if (is_nullorempty($custom_table)) {
                continue;
            }

            $tableName = getDBTableName($custom_table);

            // chunked like every other id list; the controller cap (MAX_CHECK_KEYS) already
            // keeps one request at a single chunk
            foreach (array_chunk(array_values($morphIds), 1000) as $chunk) {
                $ids = $this->pendingQuery($custom_table, true)
                    ->distinct()
                    ->whereIn($tableName . '.id', $chunk)
                    ->pluck($tableName . '.id');

                foreach ($ids as $id) {
                    $keys[] = static::taskKey($customTableId, $id);
                }
            }
        }

        if (empty($keys)) {
            return 0;
        }

        $this->markSeen($keys);

        return count($keys);
    }

    /**
     * The mirror of markAllSeen(): drop the seen marks of the current login user again.
     *
     * Only the marks are removed - no record is touched, so this is a display-state reset that
     * brings the navbar badge back. Nothing else in the product reads workflow_task_reads.
     *
     * Scoped like markAllSeen(): pendingQuery() carries the screen filter, so the button clears
     * the list the user is looking at and not a different one. Marks for records that are no
     * longer pending are left alone; they are invisible anyway and would only cost a full scan
     * of the user's history to find.
     *
     * @return int number of marks removed
     */
    public function markAllUnseen(): int
    {
        // these counts are exactly what this changes, and so is the cached navbar payload
        $this->unseenCounts = null;
        $this->allCounts = null;
        static::navbarCacheForget();

        if (!$this->hasBaseUser()) {
            return 0;
        }

        $userId = \Exment::getUserId();
        $removed = 0;

        foreach ($this->workflowCustomTables() as $customTableId => $custom_table) {
            $tableName = getDBTableName($custom_table);

            $ids = $this->pendingQuery($custom_table, false)
                ->distinct()
                ->pluck($tableName . '.id')
                ->all();

            // chunked for the same reason markSeen() chunks its inserts: one user can be the
            // work user of every record of a table, and an unbounded IN list fails as a whole
            foreach (array_chunk($ids, 1000) as $chunk) {
                $removed += WorkflowTaskRead::where('target_user_id', $userId)
                    ->where('custom_table_id', $customTableId)
                    ->whereIn('morph_id', $chunk)
                    ->delete();
            }
        }

        return $removed;
    }

    /**
     * Task keys of every un-actioned, not yet seen task of the current login user.
     * Selects the record id only - no model is built, no label or url is resolved.
     *
     * @return array<string>
     */
    private function pendingKeys(): array
    {
        $keys = [];

        foreach ($this->workflowCustomTables() as $customTableId => $custom_table) {
            $tableName = getDBTableName($custom_table);

            $ids = $this->pendingQuery($custom_table, true)
                ->distinct()
                ->pluck($tableName . '.id');

            foreach ($ids as $id) {
                $keys[] = static::taskKey($customTableId, $id);
            }
        }

        return $keys;
    }
}
