<?php

namespace Exceedone\Exment\Services\Workflow;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\WorkflowNextType;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\RelationTable;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowConditionHeader;
use Exceedone\Exment\Model\WorkflowTable;
use Exceedone\Exment\Model\WorkflowTaskRead;
use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Feature 1: gather the current login user's un-actioned workflow tasks across
 * every workflow-enabled table, and manage the per-user "seen" state (so the
 * navbar icon can show an unseen-count badge, like the notification bell).
 *
 * A task is a record the user can act on right now: they are a work user of its current status
 * AND the record page offers them an action that runs (see executableActionFilter()). "Delete" on the
 * list takes a task off this user's own list (hideSelected()); the record itself is never touched.
 *
 * The navbar polls this every few minutes for EVERY logged-in browser, so the count
 * and the dropdown must never read more than they show:
 *  - countUnseen()      one COUNT per workflow table, no row leaves the database
 *  - topUnseen($limit)  at most $limit rows per table, and only for the tables the
 *                       COUNT already proved are not empty
 *  - getPage($p, $n)    reads id + 更新日時 (taskUpdatedAt()) only, then loads just the page it shows
 * getTasks() reads everything and is kept for the callers that really do need every task
 * at once (tests, and anything that has to look at the whole list).
 *
 * The screen filter is spelled out as a type once and referred to by name afterwards. It is
 * the one array here whose KEYS are read as code - pendingQuery() branches on 'seen',
 * getPage() on 'sort', the view renders every entry back into its own form - so a bare
 * "array" would hide exactly the part that has to stay in step across the places reading it.
 * The execution conditions whereCondition() decides are spelled out the same way (see
 * conditionHeaders()): a column condition carries its column item and both halves of its view
 * filter class, a condition on the login user none of them.
 *
 * @phpstan-type TaskFilter array{custom_table_id: int|null, seen: int|null, status: string|null, from: string|null, to: string|null, q: string|null, sort: string}
 * @phpstan-type ConditionPlan array{condition: Condition, item: CustomItem|null, compare: ViewFilterBase|null, filter: ViewFilterBase|null}
 * @phpstan-type HeaderPlan array{or: bool, reverse: bool, conditions: array<int, ConditionPlan>}
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
     * Longest status name there is: workflow_statuses.status_name and workflows.start_status_name
     * are both string(30). A longer value can name no status.
     */
    const MAX_STATUS_NAME_LENGTH = 30;

    /**
     * Rows per bulk INSERT into workflow_task_reads. SQL Server takes at most 2,100 parameters in
     * one statement and Laravel binds every value: a row of up to 8 columns allows 262 rows, 250
     * leaves room. MySQL would take far more; the price there is a few more statements.
     */
    private const INSERT_CHUNK_ROWS = 250;

    /**
     * The first year the date filter compares against. Every record was written by this
     * application, so an earlier date bounds nothing - and SQL Server cannot even compare one:
     * the datetime of its value tables starts in 1753 (see applyFilter()).
     */
    private const FIRST_YEAR = 1900;

    /**
     * The name the reads give the 更新日時 of a task in their select list (see taskUpdatedAt()).
     * Not updated_at: that is the record's own column, a different time.
     */
    private const UPDATED_AT = 'task_updated_at';

    /**
     * The "seen" value of the list of the tasks the user took off their list (削除済み), next to
     * 0 (unread) and 1 (read). Those tasks are on no other list, so this is where they are found
     * again and put back (restoreSelected()). The screen does not offer it as a 状態 choice but
     * as a list of its own, like the deleted data of a grid (see WorkflowTaskController::index()).
     */
    const SEEN_REMOVED = 2;

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
            // 0 = unread only, 1 = read only, 2 = taken off the list (SEEN_REMOVED), null = on the list
            'seen'            => in_array($seen, ['0', '1', '2', 0, 1, self::SEEN_REMOVED], true) ? (int)$seen : null,
            // the NAME of the current workflow status, null = any (see statusTarget())
            'status'          => self::normalizeStatus(array_get($input, 'status')),
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
     * A status name, or null. Not cut like the keyword: a cut name could be the name of another
     * status, and a name longer than any status can have is no status at all.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function normalizeStatus($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || mb_strlen($value) > self::MAX_STATUS_NAME_LENGTH ? null : $value;
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
     * Is anything filtered at all, the free word included? Decides whether the screen may speak
     * of "all" tasks - the sort direction is not a filter, it never hides a row.
     *
     * @return bool
     */
    public function isFiltered(): bool
    {
        foreach (['custom_table_id', 'seen', 'status', 'from', 'to', 'q'] as $key) {
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
     * The status names of the workflows of those tables, for the 現在のステータス select box: the
     * start status first, then the statuses in their workflow order. The box offers NAMES,
     * like the column shows them - two workflows with a "waiting" status give one entry, and
     * picking it finds the tasks waiting in either (the table filter narrows to one of them).
     * Built without any filter, like tableOptions().
     *
     * A list, not a name => name map: PHP turns an array key like "1" into the integer 1, and a
     * status may well be called "1".
     *
     * Trimmed, like the value normalizeFilter() makes of the one sent back: a name stored with a
     * space around it (an import can do that, the settings screen cannot) is still found by it.
     *
     * @return array<int, string> status names
     */
    public static function statusOptions(): array
    {
        $names = [];

        foreach ((new self())->workflowCustomTables() as $custom_table) {
            // every workflow a task of the table can be in (see attachedWorkflows())
            foreach (self::attachedWorkflows($custom_table) as $workflow) {
                $names[] = $workflow->start_status_name;
                foreach ($workflow->workflow_statuses_cache->sortBy('order') as $status) {
                    $names[] = $status->status_name;
                }
            }
        }

        $names = array_filter(array_map(function ($name) {
            return trim(strval($name));
        }, $names), function ($name) {
            return $name !== '';
        });

        // the first place a name appears decides its place in the box
        return array_values(array_unique($names));
    }

    /**
     * What the status filter means for one table: the ids of the statuses of its workflows that
     * carry the name, and where a start status does. The start status is not a row of
     * workflow_statuses - a record is in it while no action has run on it, or when the last
     * action led back to it (WorkflowAction::forwardWorkflowValue() stores NULL for that).
     *
     * Which start that is depends on the workflow, as the column prints it
     * (CustomValue::workflow_status_name): led back, the start of the workflow of the record's
     * value; no action yet, the start of the workflow in use today, where it would start. Found in
     * review: a start name matched every record at any start - with two workflows of different
     * start names, the filter listed records whose column showed the other name.
     *
     * null: no workflow of this table has a status of that name, so none of its records can be
     * in it and the table is not read at all (see workflowCustomTables()).
     *
     * @param CustomTable $custom_table
     * @return array{ids: array<int, int>, backToStart: array<int, int>, notStarted: bool}|null
     */
    private function statusTarget(CustomTable $custom_table): ?array
    {
        $name = $this->filter['status'];
        if (is_null($name)) {
            return null;
        }

        $ids = [];
        $backToStart = [];
        // every workflow a task of the table can be in: a record carried on by a workflow whose
        // period is over is at one of THAT workflow's statuses (see attachedWorkflows())
        foreach (self::attachedWorkflows($custom_table) as $workflow) {
            // trimmed on both sides, as statusOptions() offers the names
            foreach ($workflow->workflow_statuses_cache as $status) {
                if (trim((string)$status->status_name) === $name) {
                    $ids[] = (int)$status->id;
                }
            }
            if (trim((string)$workflow->start_status_name) === $name) {
                $backToStart[] = (int)$workflow->id;
            }
        }

        $current = Workflow::getWorkflowByTable($custom_table);
        $notStarted = isset($current) && trim((string)$current->start_status_name) === $name;

        return empty($ids) && empty($backToStart) && !$notStarted
            ? null
            : ['ids' => $ids, 'backToStart' => $backToStart, 'notStarted' => $notStarted];
    }

    /**
     * The workflows a record of this table can be in: attached to it (利用設定) and switched on,
     * whether or not today lies in their period. The one in use today comes first.
     *
     * A workflow whose period is over starts nothing new, but carries the records already in it
     * to the end: the record page offers their actions - it reads the workflow of the record's own
     * workflow value - and the 利用設定 screen says as much (「現在進行中のワークフローは、変更前の
     * ワークフローで実行されます」). Those records are still work. Found in review: once the period
     * was over, the whole table was skipped, and an approver lost every request still waiting for
     * them while the record page kept asking them to approve.
     * A workflow switched off (active_flg) is left out, like the work-user views leave it out.
     *
     * @param CustomTable $custom_table
     * @return Collection<int, Workflow> keyed by workflow id
     */
    private static function attachedWorkflows(CustomTable $custom_table): Collection
    {
        // allRecordsCache() is declared as "collection OR one record OR null" (the shared trait
        // serves both shapes); it is always the whole list here
        /** @var Collection<int, WorkflowTable> $workflowTables */
        $workflowTables = WorkflowTable::allRecordsCache();

        // map() may yield null and the filter() after it drops exactly those
        /** @var Collection<int, Workflow> $workflows */
        $workflows = $workflowTables
            ->filter(function ($workflowTable) use ($custom_table) {
                return $workflowTable->custom_table_id == $custom_table->id && boolval($workflowTable->active_flg);
            })
            ->map(function ($workflowTable) {
                return Workflow::getEloquent($workflowTable->workflow_id);
            })
            ->filter(function ($workflow) {
                return !is_nullorempty($workflow) && boolval($workflow->setting_completed_flg);
            })
            ->keyBy('id');

        $current = Workflow::getWorkflowByTable($custom_table);

        return $workflows->sortBy(function ($workflow) use ($current) {
            return isset($current) && $workflow->id == $current->id ? 0 : 1;
        });
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
     * WorkflowAction::forwardWorkflowValue() deletes the read rows of a record whenever an
     * action is executed on it, which is what makes the task unseen again.
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
     * Custom tables that can hold a task, keyed by custom table id: those with a switched-on,
     * completed workflow (attachedWorkflows()). Not only those with a workflow in use TODAY: a
     * workflow whose period is over still carries its records to the end. Which workflow a record
     * that has not started would start in is Workflow::getWorkflowByTable() - the rule of the rest
     * of the product - and executableActionFilter() applies it to the start half of the query.
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
            ->map(function ($customTableId) {
                return CustomTable::getEloquent($customTableId);
            })
            ->filter(function ($custom_table) {
                if (is_nullorempty($custom_table)
                    || self::attachedWorkflows($custom_table)->isEmpty()
                    || !$this->hasAnyAccess($custom_table)) {
                    return false;
                }

                // filtering here and not in every loop: a table that is filtered out is never
                // counted, never read and never opened - it costs nothing at all
                $only = $this->filter['custom_table_id'];
                if (!is_null($only) && $custom_table->id != $only) {
                    return false;
                }

                // the same for a status name the workflow of the table does not have
                return is_null($this->filter['status']) || !is_null($this->statusTarget($custom_table));
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
     * Records of $custom_table the current login user still has to act on and still has on
     * their list.
     *
     * $onlyUnseen adds the "not read yet" filter as SQL. It is a NOT EXISTS on
     * (target_user_id, custom_table_id, morph_id) - exactly the leading columns of the unique
     * index of workflow_task_reads - so the badge can be a plain COUNT and no pending row ever
     * has to be fetched, hydrated and thrown away just to be counted.
     *
     * A task the user took off the list (hideSelected()) is left out of every read: it has a mark
     * row, so the "not read yet" filter already drops it, and every other read drops it by the
     * flag on that row. The one exception is the 削除済み list (SEEN_REMOVED), which reads those
     * tasks and nothing else.
     *
     * @param CustomTable $custom_table
     * @param bool $onlyUnseen
     * @param bool $removed read the 削除済み list whatever the filter says, under the rest of it:
     *                      what countRemoved() counts next to the other lists
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    private function pendingQuery(CustomTable $custom_table, bool $onlyUnseen, bool $removed = false)
    {
        $tableName = getDBTableName($custom_table);

        // clone: the memo must stay untouched, every caller adds its own select/order/limit.
        // The scopes are applied at execution time, so cloning before that changes nothing.
        $query = clone $this->baseQuery($custom_table);

        // $onlyUnseen is what the caller needs (badge, navbar); the screen filter can ask for
        // any side. Asking for two at once is not a contradiction to guard against - it is how
        // "how many unread rows does this read-only filter have" correctly answers zero.
        $seen = $removed ? self::SEEN_REMOVED : $this->filter['seen'];
        if ($onlyUnseen || $seen === 0) {
            // no mark at all: neither read nor taken off the list
            $query->whereNotExists($this->markSubQuery($custom_table, $tableName));
        } elseif (is_null($seen)) {
            // on the list, read or not
            $query->whereNotExists($this->markSubQuery($custom_table, $tableName, true));
        }
        if ($seen === 1) {
            // read, and still on the list
            $query->whereExists($this->markSubQuery($custom_table, $tableName, false));
        } elseif ($seen === self::SEEN_REMOVED) {
            // taken off the list, and only those
            $query->whereExists($this->markSubQuery($custom_table, $tableName, true));
        }

        $this->applyFilter($query, $custom_table, $tableName);

        return $query;
    }

    /**
     * "The current user has a mark on this record" as a correlated sub query: they have opened
     * it, and with $hidden = true, also taken it off their list. null asks for either.
     *
     * The conditions are in the order (target_user_id, custom_table_id, morph_id) - exactly the
     * leading columns of the unique index of workflow_task_reads. There is at most one row per
     * user and record, so the flag is read from that one row and needs no index of its own.
     *
     * @param CustomTable $custom_table
     * @param string $tableName
     * @param bool|null $hidden
     * @return \Closure
     */
    private function markSubQuery(CustomTable $custom_table, string $tableName, ?bool $hidden = null): \Closure
    {
        $readTable = $this->readTableName();
        $userId = \Exment::getUserId();

        return function ($sub) use ($readTable, $tableName, $custom_table, $userId, $hidden) {
            $sub->selectRaw('1')
                ->from($readTable)
                ->where($readTable . '.target_user_id', $userId)
                ->where($readTable . '.custom_table_id', $custom_table->id)
                ->whereColumn($readTable . '.morph_id', $tableName . '.id');

            if (!is_null($hidden)) {
                $sub->where($readTable . '.hidden_flg', $hidden);
            }
        };
    }

    /**
     * Add the status, the date range and the keyword of the screen filter.
     *
     * @param \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query
     * @param CustomTable $custom_table
     * @param string $tableName
     * @return void
     */
    private function applyFilter($query, CustomTable $custom_table, string $tableName): void
    {
        $this->whereStatus($query, $custom_table, $tableName);

        // The 更新日時 the column prints (taskUpdatedAt()), compared against a plain string
        // instead of whereDate(): whereDate() wraps it in date(), which differs per database.
        // Dates before FIRST_YEAR bound nothing, and SQL Server failed the whole statement on
        // them - its datetime starts in 1753, and the list answered 500 (found in review).
        [$updatedAt, $bindings] = self::taskUpdatedAt($custom_table, $tableName);
        if (!is_null($this->filter['from']) && (int)substr($this->filter['from'], 0, 4) >= self::FIRST_YEAR) {
            $query->whereRaw($updatedAt . ' >= ?', array_merge($bindings, [$this->filter['from'] . ' 00:00:00']));
        }
        if (!is_null($this->filter['to'])) {
            // The end date is inclusive, so the bound is the start of the following day. The last
            // date there is has none: "10000-01-01" is no date to the database, and MySQL fails
            // the whole statement on it (error 1525) - the list answered 500. Every record lies
            // before the end of 9999-12-31, so that end date bounds nothing.
            $next = \Carbon\Carbon::parse($this->filter['to'])->addDay();
            if ($next->year < self::FIRST_YEAR) {
                // and none lies before FIRST_YEAR
                $query->whereRaw('1 = 0');
            } elseif ($next->year <= 9999) {
                $query->whereRaw($updatedAt . ' < ?', array_merge($bindings, [$next->format('Y-m-d') . ' 00:00:00']));
            }
        }

        if (is_null($this->filter['q'])) {
            return;
        }

        $columns = $this->searchColumns($custom_table);
        $keyword = $this->filter['q'];
        $like = self::containsPattern($keyword);

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
     * "The record is in the status the filter names", on the latest workflow value of the record:
     * the same row CustomValue::workflow_value reads to print the 現在のステータス column.
     *
     * Correlated sub queries on (morph_type, morph_id), the index workflow_values already has.
     *
     * @param \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query
     * @param CustomTable $custom_table
     * @param string $tableName
     * @return void
     */
    private function whereStatus($query, CustomTable $custom_table, string $tableName): void
    {
        if (is_null($this->filter['status'])) {
            return;
        }

        $target = $this->statusTarget($custom_table);
        if (is_null($target)) {
            // workflowCustomTables() never lets such a table through; if it ever did, no record
            // of it is in that status
            $query->whereRaw('1 = 0');
            return;
        }

        $valueTable = SystemTableName::WORKFLOW_VALUE;
        $latest = function ($sub) use ($valueTable, $custom_table, $tableName) {
            $sub->selectRaw('1')
                ->from($valueTable)
                ->whereColumn($valueTable . '.morph_id', $tableName . '.id')
                ->where($valueTable . '.morph_type', $custom_table->table_name)
                ->where($valueTable . '.latest_flg', 1);
        };

        $query->where(function ($query) use ($target, $latest, $valueTable) {
            if (!empty($target['ids'])) {
                $query->orWhereExists(function ($sub) use ($latest, $valueTable, $target) {
                    $latest($sub);
                    $sub->whereIn($valueTable . '.workflow_status_to_id', $target['ids']);
                });
            }

            if (!empty($target['backToStart'])) {
                // the last action led back to the start of one of those workflows
                $query->orWhereExists(function ($sub) use ($latest, $valueTable, $target) {
                    $latest($sub);
                    $sub->whereNull($valueTable . '.workflow_status_to_id')
                        ->whereIn($valueTable . '.workflow_id', $target['backToStart']);
                });
            }

            if ($target['notStarted']) {
                // no action yet: it starts in the workflow in use today (see statusTarget())
                $query->orWhereNotExists(function ($sub) use ($latest) {
                    $latest($sub);
                });
            }
        });
    }

    /**
     * The 更新日時 of a task, as SQL: when the previous action ran on the record - the moment the
     * task came to the step it waits at - or, while the record waits at the start, when the record
     * itself was last updated.
     *
     * It was the record's own updated_at, which says nothing about how long a task has waited:
     * any edit of the record moves it, by whoever, an import as much as the person who has to
     * act. A request held up at its step for months read as yesterday's, and the list - oldest
     * first, to bring up what has been held up longest - put it anywhere but at the top (pointed
     * out by the customer on v6.2.14).
     *
     * "The previous action" is the last action carried out on the record: the one that brought it
     * to the status it is at. One approval of a step that waits for several is not one yet: the
     * status stays until the last of them acts, and the approvers still to act, the ones who have
     * the task, have had it since the record came to that status. Such an approval is the workflow
     * value with action_executed_flg set, until the step moves on and clears it
     * (WorkflowAction::forwardWorkflowValue()) - the 実行ユーザー of the workflow settings skips it
     * the same way (WorkflowValue::getLastExecutedWorkflowValue()). It still makes the task unread
     * again: that says somebody acted, this says how long the task has waited.
     *
     * At the start the record is its own, whichever way it came to be there: a record no action
     * has run on yet, and one an action took back there alike - the customer asked for the start to
     * go by the record, so an edit there moves it on both. The record is at the start while it has
     * no workflow value, or its latest one leads to no status (workflow_status_to_id NULL): the row
     * CustomValue::workflow_value reads for the 現在のステータス column. One approval of several
     * leaves that row at the status the record is at (WorkflowAction::getStatusToId()).
     *
     * Correlated sub queries on (morph_type, morph_id), the index workflow_values already has.
     * MAX() keeps it one value per record whatever the table holds. Every read selects, sorts and
     * filters by this one expression, so the screen prints the time it sorted by.
     *
     * @param CustomTable $custom_table
     * @param string $tableName
     * @return array{0: string, 1: array<int, mixed>} the expression and its bindings
     */
    private static function taskUpdatedAt(CustomTable $custom_table, string $tableName): array
    {
        $valueTable = SystemTableName::WORKFLOW_VALUE;
        $grammar = \DB::getQueryGrammar();
        $updatedAt = $grammar->wrap($tableName . '.updated_at');

        // the record has left the start: its latest workflow value leads to a status
        $underway = \DB::table($valueTable)
            ->selectRaw('1')
            ->whereColumn($valueTable . '.morph_id', $tableName . '.id')
            ->where($valueTable . '.morph_type', $custom_table->table_name)
            ->where($valueTable . '.latest_flg', 1)
            ->whereNotNull($valueTable . '.workflow_status_to_id');

        $executed = \DB::table($valueTable)
            ->selectRaw('MAX(' . $grammar->wrap($valueTable . '.created_at') . ')')
            ->whereColumn($valueTable . '.morph_id', $tableName . '.id')
            ->where($valueTable . '.morph_type', $custom_table->table_name)
            ->where($valueTable . '.action_executed_flg', 0);

        // COALESCE() inside: a record that left the start had an action carried out on it, but the
        // time must not turn NULL on workflow values written some other way (import, by hand)
        return [
            'CASE WHEN EXISTS (' . $underway->toSql() . ')'
                . ' THEN COALESCE((' . $executed->toSql() . '), ' . $updatedAt . ')'
                . ' ELSE ' . $updatedAt . ' END',
            array_merge($underway->getBindings(), $executed->getBindings()),
        ];
    }

    /**
     * Add the 更新日時 of the task (taskUpdatedAt()) to the select list of $query, as UPDATED_AT.
     *
     * @param \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query
     * @param CustomTable $custom_table
     * @param string $tableName
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    private static function selectUpdatedAt($query, CustomTable $custom_table, string $tableName)
    {
        [$updatedAt, $bindings] = self::taskUpdatedAt($custom_table, $tableName);

        return $query->selectRaw($updatedAt . ' as ' . \DB::getQueryGrammar()->wrap(self::UPDATED_AT), $bindings);
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
        // reuse the existing, tested "records the current user must act on" logic, narrowed to
        // the actions this user can actually run (see executableActionFilter())
        RelationTable::setWorkflowWorkUsersSubQuery($query, $custom_table, false, $this->executableActionFilter($custom_table));

        return $this->baseQueries[$customTableId] = $query;
    }

    /**
     * Narrows the work-user query to the actions the current user can actually run.
     *
     * The work-user query knows who an action is FOR. Two more things decide whether the record
     * page offers that user a button that does something (CustomValue::getWorkflowActions(),
     * WorkflowAction::actionModal()):
     *  - the execution condition of the action (実行条件): where no condition header matches the
     *    record, the page has no button for it;
     *  - a step that waits for several approvers keeps its status until the last of them acts.
     *    The ones who already did are still work users of that status, with nothing left to
     *    press (WorkflowValue::isAlreadyExecuted()).
     * And a special action (特殊なアクション, ignore_work) makes nobody a work user at all - the
     * settings screen says so, and the work actions of the record page leave it out. The value
     * view drops such actions already; the start view keeps them, so without this everybody
     * allowed e.g. a 代理申請 at the start had every record not started yet on their list.
     *
     * The actions are those of every workflow a record of the table can be in (attachedWorkflows()):
     * a record carried on by a workflow whose period is over is checked against THAT workflow's
     * actions. A record that has not started would start in the workflow in use today
     * (Workflow::getWorkflowByTable(), where the record page looks for it), so the start half keeps
     * that workflow's start actions only - the start view knows every switched-on workflow of the
     * table, the one whose period is over or has not begun yet included - and none at all when no
     * workflow is in use today.
     *
     * Both are added per action, to the (record, action) rows of the work-user query - one user
     * can be allowed one action of a status and not another - so every count, page and batch
     * action stays one SQL statement and no record is loaded to decide it. The navbar polls this
     * for every open browser, so it must never read more rows than it shows. Checking in PHP what
     * the record page checks costs ~5 ms per record here (measured: 1,000 records at such a
     * status took 4.8 s), almost all of it CustomColumn::getEloquent() inside the comparison.
     *
     * @param CustomTable $custom_table
     * @return \Closure|null null when nothing needs narrowing: the work-user query is then exactly
     *                       what it always was
     */
    private function executableActionFilter(CustomTable $custom_table): ?\Closure
    {
        $workflows = self::attachedWorkflows($custom_table);
        if ($workflows->isEmpty()) {
            return null;
        }

        // what a record that has not started can be started by: the workflow in use today, alone
        $current = Workflow::getWorkflowByTable($custom_table);
        $startWorkflowId = isset($current) ? (int)$current->id : null;
        // with a single workflow that is in use today, the start view holds nothing else
        $narrowStart = is_null($startWorkflowId) || $workflows->count() > 1;

        // the conditions of every header in one query, instead of one query per header the first
        // time runsOnEveryRecord() asks it (measured: 15 of the 55 queries of a navbar poll).
        // hasManyCache() hands out the very objects of this collection, so they read what is
        // loaded here - and so does the record page's own check later in the same request.
        $allHeaders = WorkflowConditionHeader::allRecordsCache();
        if ($allHeaders instanceof \Illuminate\Database\Eloquent\Collection) {
            $allHeaders->loadMissing('workflow_conditions');
        }

        $tableName = getDBTableName($custom_table);

        // action id => [its condition headers (null: nothing to check), waits for several approvers]
        // - action ids are unique across workflows, so one map serves every workflow of the table
        $checks = [];
        // the special actions, which are never a task (see above)
        $special = [];
        foreach ($workflows as $workflow) {
            foreach ($workflow->workflow_actions_cache as $action) {
                if (boolval($action->ignore_work)) {
                    $special[] = (int)$action->id;
                    continue;
                }

                $headers = self::runsOnEveryRecord($action) ? null : self::conditionHeaders($action, $custom_table, $tableName);
                $severalApprovers = !self::endsAtFirstExecution($action);
                if (isset($headers) || $severalApprovers) {
                    $checks[(int)$action->id] = [$headers, $severalApprovers];
                }
            }
        }

        if (empty($checks) && empty($special) && !$narrowStart) {
            return null;
        }

        $valueTable = SystemTableName::WORKFLOW_VALUE;
        $userId = \Exment::getUserId();

        return function ($query, string $viewName) use ($checks, $special, $narrowStart, $startWorkflowId, $custom_table, $tableName, $valueTable, $userId) {
            // the start half: the workflow in use today, or nothing to start at all
            if ($narrowStart && $viewName === SystemTableName::VIEW_WORKFLOW_START) {
                if (is_null($startWorkflowId)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->where($viewName . '.workflow_id', $startWorkflowId);
                }
            }

            // on both halves, as one rule; only the start half has such rows to drop
            if (!empty($special)) {
                $query->whereNotIn($viewName . '.workflow_action_id', $special);
            }

            if (empty($checks)) {
                return;
            }

            $query->where(function ($query) use ($checks, $custom_table, $tableName, $valueTable, $userId, $viewName) {
                // the rows of every other action pass as they are
                $query->whereNotIn($viewName . '.workflow_action_id', array_keys($checks));

                foreach ($checks as $actionId => [$headers, $severalApprovers]) {
                    $query->orWhere(function ($query) use ($actionId, $headers, $severalApprovers, $custom_table, $tableName, $valueTable, $userId, $viewName) {
                        $query->where($viewName . '.workflow_action_id', $actionId);

                        if (isset($headers)) {
                            $query->where(function ($query) use ($headers, $custom_table) {
                                $this->whereExecutionCondition($query, $headers, $custom_table);
                            });
                        }

                        // this user has not done their part of the step yet
                        if ($severalApprovers) {
                            $query->whereNotExists(function ($sub) use ($actionId, $custom_table, $tableName, $valueTable, $userId) {
                                $sub->selectRaw('1')
                                    ->from($valueTable)
                                    ->whereColumn($valueTable . '.morph_id', $tableName . '.id')
                                    ->where($valueTable . '.morph_type', $custom_table->table_name)
                                    ->where($valueTable . '.workflow_action_id', $actionId)
                                    ->where($valueTable . '.action_executed_flg', 1)
                                    ->where($valueTable . '.created_user_id', $userId);
                            });
                        }
                    });
                }
            });
        };
    }

    /**
     * The condition headers of an action, ready for whereExecutionCondition(), or null when one
     * of its conditions cannot be put into SQL here.
     *
     * The settings screen only makes conditions on the table's own columns (WorkflowController::
     * conditionModal()), and those are what is translated, together with the login user,
     * organization and role conditions, which do not depend on the record at all. Anything else
     * can only come from an import or an older version and is not guessed at: the action is then
     * left exactly as the work-user query has it, as it was before conditions were checked.
     *
     * @param WorkflowAction $action
     * @param CustomTable $custom_table
     * @param string $tableName
     * @return array<int, HeaderPlan>|null
     */
    private static function conditionHeaders(WorkflowAction $action, CustomTable $custom_table, string $tableName): ?array
    {
        $headers = [];
        foreach ($action->workflow_condition_headers_cache as $header) {
            $conditions = [];
            foreach ($header->workflow_conditions as $condition) {
                $plan = ['condition' => $condition, 'item' => null, 'compare' => null, 'filter' => null];

                if (isMatchString($condition->condition_type, ConditionType::COLUMN)) {
                    $custom_column = CustomColumn::getEloquent($condition->target_column_id);
                    // a fresh item: the cached one of the column may carry the table alias of some
                    // earlier join
                    $item = isset($custom_column) && $custom_column->custom_table_id == $custom_table->id
                        ? CustomItem::getItem($custom_column)
                        : null;
                    if (is_null($item)) {
                        return null;
                    }

                    $plan['item'] = $item->setUniqueTableName($tableName);
                    // what the record page compares with (ConditionItemBase::compareValue()), and
                    // the SQL half of the very same class - what the data grid filters with
                    $plan['compare'] = ViewFilterBase::makeForCondition($condition);
                    $plan['filter'] = ViewFilterBase::make($condition->condition_key, $plan['item']);
                    if (is_null($plan['compare']) || is_null($plan['filter'])) {
                        return null;
                    }
                } elseif (!isMatchString($condition->condition_type, ConditionType::CONDITION)
                    || !in_array((string)$condition->target_column_id, [ConditionTypeDetail::USER, ConditionTypeDetail::ORGANIZATION, ConditionTypeDetail::ROLE], true)) {
                    return null;
                }

                $conditions[] = $plan;
            }

            $headers[] = [
                'or' => $header->condition_join === 'or',
                'reverse' => boolval($header->condition_reverse),
                'conditions' => $conditions,
            ];
        }

        return $headers;
    }

    /**
     * "At least one condition header of the action matches the record", in SQL - the twin of
     * WorkflowAction::getMatchedCondtionHeader() and WorkflowConditionHeader::isMatchCondition():
     * headers are alternatives, the conditions of one header are joined by its "and" / "or",
     * and "reverse" negates the whole header.
     *
     * "reverse" is a plain NOT, which is only right because whereCondition() never leaves a
     * condition NULL: NOT NULL is NULL, and would drop exactly the records the record page keeps.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param array<int, HeaderPlan> $headers from conditionHeaders()
     * @param CustomTable $custom_table
     * @return void
     */
    private function whereExecutionCondition($query, array $headers, CustomTable $custom_table): void
    {
        // no header at all: getMatchedCondtionHeader() finds none, so the action never runs
        if (empty($headers)) {
            $query->whereRaw('1 = 0');
            return;
        }

        foreach ($headers as $header) {
            $query->orWhere(function ($query) use ($header, $custom_table) {
                $query->{$header['reverse'] ? 'whereNot' : 'where'}(function ($query) use ($header, $custom_table) {
                    // _isMatchCondition(): "and" over no condition is true, "or" over none is false
                    if (empty($header['conditions'])) {
                        $query->whereRaw($header['or'] ? '1 = 0' : '1 = 1');
                        return;
                    }

                    foreach ($header['conditions'] as $plan) {
                        $this->whereCondition($query, $plan, $header['or'], $custom_table);
                    }
                });
            });
        }
    }

    /**
     * One execution condition, in SQL - always TRUE or FALSE, never NULL.
     *
     * The record page (ColumnItem::isMatchCondition()) reads the raw value and calls
     * compareValue(), which answers an EMPTY value on its own: it drops it before the operator
     * ever sees it, and what is left decides - so NOT EQUAL, NOT EXISTS and "is not the login
     * user" are true for an empty value, and the null check is false for a multi-select stored
     * as []. The SQL of the operator knows none of this: "<>" and NOT IN drop NULL, the null
     * check counts [] as empty. So every empty shape the database holds (missing, [] and '') is
     * answered by compareValue() itself - once, it is the same for every record - and only a
     * value that is really there goes through the operator's SQL.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param ConditionPlan $plan
     * @param bool $or joined to the conditions before it by "or"
     * @param CustomTable $custom_table
     * @return void
     */
    private function whereCondition($query, array $plan, bool $or, CustomTable $custom_table): void
    {
        $boolean = $or ? 'or' : 'and';
        $condition = $plan['condition'];
        $item = $plan['item'];
        $compare = $plan['compare'];
        $filter = $plan['filter'];

        if (!isset($item, $compare, $filter)) {
            // the login user, their organization or role: the record page's own check has one
            // answer for every record, so it is asked once, about any record of the table
            $query->whereRaw($condition->isMatchCondition($custom_table->getValueModel()) ? '1 = 1' : '1 = 0', [], $boolean);
            return;
        }

        $column = $item->getTableColumn();
        $conditionValue = $condition->condition_value;

        $query->where(function ($query) use ($column, $conditionValue, $condition, $compare, $filter) {
            if ($compare->compareValue(null, $conditionValue)) {
                $query->orWhereNull($column);
            }
            if ($compare->compareValue([], $conditionValue)) {
                $query->orWhere($column, '[]');
            }
            if ($compare->compareValue('', $conditionValue)) {
                $query->orWhere($column, '');
            }

            $query->orWhere(function ($query) use ($column, $conditionValue, $condition, $filter) {
                $query->whereNotNull($column)->whereNotIn($column, ['[]', '']);

                // LIKE / NOT LIKE are the one pair whose halves differ even then: see whereContains()
                if (isMatchString($condition->condition_key, FilterOption::LIKE) || isMatchString($condition->condition_key, FilterOption::NOT_LIKE)) {
                    $this->whereContains($query, $column, $conditionValue, isMatchString($condition->condition_key, FilterOption::LIKE));
                    return;
                }

                $filter->setFilter($query, $conditionValue);
            });
        }, null, null, $boolean);
    }

    /**
     * "The record value contains the condition value", for a value that is there - the SQL twin
     * of Like / NotLike::_compareValue() as ViewFilterBase::compareValue() calls them, which is
     * what the record page decides with. Their own SQL half (LikeBase::_setFilter()) is not that:
     * it follows the system setting filter_search_type (starts-with by default) and reads % and _
     * as wildcards, while the record page does a plain strpos().
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param string $column
     * @param mixed $conditionValue
     * @param bool $like LIKE, or NOT LIKE
     * @return void
     */
    private function whereContains($query, string $column, $conditionValue, bool $like): void
    {
        // compareValue(): the condition matches when any of its values does
        if ($conditionValue instanceof Collection) {
            $values = $conditionValue->values()->all();
        } elseif (is_array($conditionValue)) {
            $values = array_values($conditionValue);
        } else {
            $values = [$conditionValue];
        }

        $query->where(function ($query) use ($column, $values, $like) {
            if (empty($values)) {
                $query->whereRaw('1 = 0');
                return;
            }

            foreach ($values as $value) {
                if (is_null($value)) {
                    // Like::_compareValue() refuses a null condition, NotLike accepts it
                    $query->orWhereRaw($like ? '1 = 0' : '1 = 1');
                    continue;
                }

                // the same pattern as the keyword search of this screen (applyFilter())
                $query->orWhere($column, $like ? 'LIKE' : 'NOT LIKE', self::containsPattern(strval($value)));
            }
        });
    }

    /**
     * "%value%" for a LIKE that looks for $value as it is: a % or _ in it is a character to find,
     * not a wildcard.
     *
     * How to say so depends on the database. MySQL and MariaDB read a backslash as the escape
     * character of LIKE. SQL Server has none unless the statement names one - a backslash is a
     * plain character there, so "\%" would look for a backslash - and takes a wildcard between
     * brackets literally instead, which makes "[" the one more character to escape.
     *
     * @param string $value
     * @return string
     */
    private static function containsPattern(string $value): string
    {
        $escaped = \Exment::isSqlServer()
            ? str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $value)
            : str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

        return '%' . $escaped . '%';
    }

    /**
     * Does the execution condition of this action let every record through?
     *
     * Only a condition header without any condition gives the same answer for every record. With
     * the default "and" join and no reverse that answer is "yes", so the action is available
     * wherever its status is and the work-user query already has the full answer. Anything else -
     * a real condition, or an unusual empty header - goes through whereExecutionCondition().
     *
     * @param WorkflowAction $action
     * @return bool
     */
    private static function runsOnEveryRecord(WorkflowAction $action): bool
    {
        foreach ($action->workflow_condition_headers_cache as $header) {
            if (count($header->workflow_conditions) === 0
                && $header->condition_join !== 'or'
                && !boolval($header->condition_reverse)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does one execution of this action move the record on? Otherwise the users who already
     * executed it stay work users of the status until enough others have - the same rule as
     * WorkflowAction::getActionNextParams().
     *
     * @param WorkflowAction $action
     * @return bool
     */
    private static function endsAtFirstExecution(WorkflowAction $action): bool
    {
        return $action->getOption('flow_next_count', 1) == 1 && $action->flow_next_type == WorkflowNextType::SOME;
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
        [$statusName, $statusTag] = self::statusOf($custom_table, $value);

        return [
            'custom_table_id'   => $custom_table->id,
            'table_view_name'   => $custom_table->table_view_name,
            'icon'              => $custom_table->getOption('icon') ?: 'fa-tasks',
            'color'             => $custom_table->getOption('color') ?: null,
            'morph_id'          => $value->id,
            'label'             => $value->getLabel(),
            'url'               => $value->getUrl(),
            'status_name'       => $statusName,
            'status_tag'        => $statusTag,
            // the 更新日時 of the task, not of the record (see taskUpdatedAt())
            'updated_at'        => self::updatedAtOf($value),
            'task_key'          => static::taskKey($custom_table->id, $value->id),
        ];
    }

    /**
     * The 更新日時 of the task that the read of $value selected (selectUpdatedAt()), as a date
     * like the record's own updated_at: the screen prints it the same way.
     *
     * @param \Exceedone\Exment\Model\CustomValue $value
     * @return \Carbon\Carbon|null
     */
    private static function updatedAtOf($value): ?\Carbon\Carbon
    {
        $updatedAt = $value->getAttribute(self::UPDATED_AT);

        return is_nullorempty($updatedAt) ? null : \Carbon\Carbon::parse($updatedAt);
    }

    /**
     * The status a task is at, as the 現在のステータス column prints it: the name, and the tag
     * (the escaped name, with the lock icon of a locked record).
     *
     * CustomValue::workflow_status reads the status only while a workflow of the table is in use
     * today (Workflow::getWorkflowByTable()). With none in use, a record carried on by a workflow
     * whose period is over (see attachedWorkflows()) printed the START status of that workflow -
     * a request waiting for approval read as a draft, and the status filter found it under a
     * name its row did not show. Found in review. Its own workflow value knows the status. No
     * lock icon then: without a workflow in use today the record is not locked
     * (CustomValue::lockedWorkflow()).
     *
     * @param CustomTable $custom_table
     * @param \Exceedone\Exment\Model\CustomValue $value
     * @return array{0: string|null, 1: string}
     */
    private static function statusOf(CustomTable $custom_table, $value): array
    {
        if (is_null(Workflow::getWorkflowByTable($custom_table))) {
            $status = isset($value->workflow_value) ? $value->workflow_value->workflow_status_cache : null;
            if (isset($status)) {
                return [$status->status_name, esc_html($status->status_name)];
            }
        }

        return [$value->workflow_status_name, $value->workflow_status_tag];
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

        // "read only" and "taken off the list": pendingQuery() would put whereExists AND
        // whereNotExists on the same rows, so every count is zero by construction. No table has
        // to be touched to say so.
        if ($this->filter['seen'] === 1 || $this->filter['seen'] === self::SEEN_REMOVED) {
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
     * How many tasks the current user took off the list, under the rest of the filter - table,
     * status, dates, free word; not 状態, a task taken off the list is neither read nor unread.
     * The list prints it next to its total, as the way to the 削除済み list.
     *
     * Found in review: 削除済み was one more choice of the 状態 filter, next to すべて (all) - which
     * does not hold it. The list said there was no pending task while the record page kept
     * offering the button, and nothing on the screen told where the task had gone.
     *
     * Most users never take a task off: for them this is one read of their own rows of
     * workflow_task_reads, by the leading column of its unique index. Otherwise one COUNT per
     * table they took something off, not per workflow table.
     *
     * @return int
     */
    public function countRemoved(): int
    {
        // the 削除済み list itself: that is its total
        if ($this->filter['seen'] === self::SEEN_REMOVED) {
            return $this->countAll();
        }

        // empty without a user record too, so the user id below is a real one
        $tables = $this->workflowCustomTables();
        if ($tables->isEmpty()) {
            return 0;
        }

        // a mark that outlived its task (the record went to somebody else by an edit, or was
        // deleted) lets its table through here; the COUNT below finds nothing there
        $customTableIds = WorkflowTaskRead::where('target_user_id', \Exment::getUserId())
            ->where('hidden_flg', true)
            ->distinct()
            ->pluck('custom_table_id');

        $count = 0;
        foreach ($customTableIds as $customTableId) {
            $custom_table = $tables->get($customTableId);
            if (is_nullorempty($custom_table)) {
                continue;
            }

            $count += $this->pendingQuery($custom_table, false, true)
                ->distinct()
                ->count(getDBTableName($custom_table) . '.id');
        }

        return $count;
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
     * it can: each table returns at most $limit id + 更新日時 pairs, and a model is built only
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

            $query = $this->pendingQuery($custom_table, $onlyUnseen)
                ->distinct()
                ->select([$tableName . '.id']);
            $rows = self::selectUpdatedAt($query, $custom_table, $tableName)
                ->orderBy(self::UPDATED_AT, 'desc')
                ->orderBy($tableName . '.id', 'desc')
                ->limit($limit)
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                $index[] = self::indexEntry($customTableId, $row->id, $row->{self::UPDATED_AT});
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

            $query = $this->pendingQuery($custom_table, false)
                ->with(['workflow_value'])
                ->select($tableName . '.*')
                ->distinct();
            $values = self::selectUpdatedAt($query, $custom_table, $tableName)->get();

            // the query is built from getModelName(), whose class is only known at runtime, so
            // the rows arrive typed as the base Model; every custom value table extends CustomValue
            /** @var \Exceedone\Exment\Model\CustomValue $value */
            foreach ($values as $value) {
                $rows->push($this->taskRow($custom_table, $value));
            }
        }

        // the 更新日時 of the task, which taskRow() put under the key of the column
        return $rows->sortByDesc('updated_at')->values();
    }

    /**
     * One page of the task list, newest first across every workflow table.
     *
     * The screen shows $perPage rows, so only $perPage records are turned into a model.
     * Sorting across tables cannot be one SQL statement (each custom table is a physical table
     * of its own), so it is done in two steps:
     *  1. per table, read id + 更新日時 of its newest ($page * $perPage) pending records.
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
            $query = $this->pendingQuery($custom_table, false)
                ->distinct()
                ->select([$tableName . '.id']);
            $rows = self::selectUpdatedAt($query, $custom_table, $tableName)
                ->orderBy(self::UPDATED_AT, $direction)
                ->orderBy($tableName . '.id', $direction)
                // min(): SELECT DISTINCT id, 更新日時 returns one row per distinct id (one
                // 更新日時 per record), which is exactly what countAll() counted
                ->limit(min($head, $tableTotal))
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                $index[] = self::indexEntry($customTableId, $row->id, $row->{self::UPDATED_AT});
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
     * single string: 更新日時 (taskUpdatedAt()), morph id, custom table id - fixed width, zero
     * padded, so byte order IS the sort order (更新日時 first, id as tie breaker, table id last).
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
     * @param mixed $updatedAt the 更新日時 of the task, "Y-m-d H:i:s" from the database; NULL becomes
     *                         spaces, which sort before every real date - the end MySQL puts
     *                         NULLs at, so the PHP merge and the SQL ORDER BY agree
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
            $query = getModelName($custom_table->table_name)::query()
                ->with(['workflow_value'])
                ->select($tableName . '.*')
                ->whereIn($tableName . '.id', $ids);
            $values = self::selectUpdatedAt($query, $custom_table, $tableName)->get();

            // as in getTasks(): every custom value table extends CustomValue
            /** @var \Exceedone\Exment\Model\CustomValue $value */
            foreach ($values as $value) {
                $row = $this->taskRow($custom_table, $value);
                $row['seen'] = isset($seen[$row['task_key']]);
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
     * moves on every own write: a poll that was already running when the write happened stores
     * its answer - by then an old one - under the old version, where no later poll looks.
     *
     * @return string|null null when there is no user record to key on
     */
    public static function navbarCacheKey(): ?string
    {
        $userId = \Exment::getUserId();
        if (is_nullorempty($userId)) {
            return null;
        }

        $version = (int)\Cache::get(self::navbarVersionKey($userId), 0);

        return 'exment_workflow_task_nav_' . $userId . '_' . $version . '_' . app()->getLocale();
    }

    /**
     * Where the version of a user's navbar payload is kept (see navbarCacheKey()).
     *
     * @param int|string $userId
     * @return string
     */
    private static function navbarVersionKey($userId): string
    {
        return 'exment_workflow_task_nav_ver_' . $userId;
    }

    /**
     * Make the CURRENT user's next navbar poll recompute instead of answering from the cache.
     *
     * Called wherever this user's own view of the list changes, AFTER the write: marking or
     * unmarking here, and - through navbarCacheForgetAfterCommit() - acting on a workflow
     * (WorkflowAction::forwardWorkflowValue) and deleting a record (CustomValue). Moved before
     * the write, a poll in between would store the old list under the new version. What OTHER
     * users change reaches a cached badge when the cache expires - within one poll interval,
     * the same delay polling itself already has.
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
            // The payload of the version that ends here is never read again, and expiring does
            // not remove it: the file store deletes an expired entry only when that very key is
            // read. Left alone, every own write kept one dead file on disk until cache:clear.
            \Cache::forget((string)static::navbarCacheKey());

            // Read and write back, not increment(): the database and memcached stores answer
            // false for a key that does not exist yet and store nothing, so there the version
            // never moved and the badge stayed stale for a whole poll interval. Two writes racing
            // here may store the same number; the version still moves off the one any poll can
            // have cached, which is all it is for.
            $versionKey = self::navbarVersionKey($userId);
            \Cache::forever($versionKey, (int)\Cache::get($versionKey, 0) + 1);
        } catch (\Throwable $ex) {
            // a cache backend refusing the write must never break the action being performed
        }
    }

    /**
     * navbarCacheForget() once the transaction this runs in has committed; right away outside one.
     *
     * For the writes core makes inside a transaction: executing an action, deleting a record.
     * Moving the version in there went wrong twice (found in review):
     *  - on the database cache store the version is a row of the cache table, written by the
     *    transaction of the action itself. Two actions at once could deadlock on that table - the
     *    DELETE of a payload that is not there locks the gap, the INSERT of the version waits for
     *    the gap of the other one - and InnoDB then rolls the whole action back, while the catch
     *    in navbarCacheForget() swallows the error: the approval was lost, the page said it went
     *    through;
     *  - on every store, a poll between the move and the commit read the list as it was and
     *    stored it under the new version, for a whole poll interval.
     * A transaction rolled back drops the callback: nothing changed, nothing to move.
     *
     * @return void
     */
    public static function navbarCacheForgetAfterCommit(): void
    {
        try {
            \DB::afterCommit(function () {
                static::navbarCacheForget();
            });
        } catch (\Throwable $ex) {
            // a connection without a transactions manager (none is set outside the framework)
            static::navbarCacheForget();
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
        // whatever happens below, the memoised counts are no longer trustworthy. Both counts
        // go: under "unread only" the all-count is a count of unseen rows too, so this changes
        // it. The navbar payload cached for this user goes once the marks are written.
        $this->unseenCounts = null;
        $this->allCounts = null;

        // no user record to own the mark - target_user_id is NOT NULL, so writing would throw
        if (!$this->hasBaseUser()) {
            return;
        }

        $userId = \Exment::getUserId();

        $byTable = self::keysByTable($taskKeys);
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
        // single INSERT carrying tens of thousands of rows would exceed max_allowed_packet (or,
        // past 300 rows, SQL Server's parameter limit) - which fails the whole statement, so
        // nothing at all would be marked as seen.
        foreach (array_chunk($inserts, self::INSERT_CHUNK_ROWS) as $chunk) {
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

        // after the marks, not before: see navbarCacheForget()
        static::navbarCacheForget();
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

        $byTable = self::keysByTable($taskKeys);

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
     * Take the SELECTED tasks off the current user's list - the "delete" of the list screen.
     *
     * The row of a task is a real record of a real table that other users work on, so nothing
     * of it is touched: only this user's own mark in workflow_task_reads gets hidden_flg. The task
     * is then gone from every count, page and dropdown of this user, and comes back - unread -
     * the next time anybody executes an action on the record, because forwardWorkflowValue()
     * clears every mark of the record there: normally that moves it to another status, and in a
     * step that waits for several approvers it is one more approval.
     *
     * The keys come from the browser, so, like markSeenSelected(), each one is checked against
     * the permission scoped work-user query of its own table first: only this user's own tasks
     * that are still on their list can be taken off it.
     *
     * A key names a record, not the step it is at, so a list that has been open for a while can
     * name a record that has moved on since - to a status that is this user's task again. Taking
     * THAT task off the list would hide a task the user has never seen. $listedAt, the moment the
     * list was drawn, rules it out: a record somebody acted on from then on is left as it is (see
     * whereNoActionSince()), and the reloaded list shows it at its new status.
     *
     * @param array<string> $taskKeys
     * @param mixed $listedAt "Y-m-d H:i:s" the list was drawn at; anything else - an older page, a
     *                        hand made request - skips that check, it does not widen anything
     * @return int how many tasks were taken off the list
     */
    public function hideSelected(array $taskKeys, $listedAt = null): int
    {
        // nothing selected: answer without asking the database at all
        if (empty($taskKeys)) {
            return 0;
        }

        $tables = $this->workflowCustomTables();
        $userId = \Exment::getUserId();
        $now = \Carbon\Carbon::now();
        $listedAt = self::normalizeMoment($listedAt);
        $hidden = 0;

        foreach (self::keysByTable($taskKeys) as $customTableId => $morphIds) {
            // not a workflow table this user may see at all: every one of its keys is dropped
            $custom_table = $tables->get($customTableId);
            if (is_nullorempty($custom_table)) {
                continue;
            }

            $tableName = getDBTableName($custom_table);

            foreach (array_chunk(array_values($morphIds), 1000) as $chunk) {
                $query = $this->pendingQuery($custom_table, false)
                    ->distinct()
                    ->whereIn($tableName . '.id', $chunk);
                if (isset($listedAt)) {
                    $this->whereNoActionSince($query, $custom_table, $tableName, $listedAt);
                }

                $ids = $query->pluck($tableName . '.id')
                    ->map(function ($id) {
                        return (int)$id;
                    })
                    ->all();

                if (empty($ids)) {
                    continue;
                }

                // a task that was already read has its row: flag it
                $marks = WorkflowTaskRead::where('target_user_id', $userId)
                    ->where('custom_table_id', $customTableId)
                    ->whereIn('morph_id', $ids);
                $stored = (clone $marks)->pluck('morph_id')->map(function ($id) {
                    return (int)$id;
                })->all();
                if (!empty($stored)) {
                    (clone $marks)->update(['hidden_flg' => true, 'updated_user_id' => $userId]);
                }

                // an unread one has none yet: insert it flagged
                $inserts = [];
                foreach (array_diff($ids, $stored) as $morphId) {
                    $inserts[] = [
                        'target_user_id'  => $userId,
                        'custom_table_id' => $customTableId,
                        'morph_id'        => $morphId,
                        'hidden_flg'      => true,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                        'created_user_id' => $userId,
                        'updated_user_id' => $userId,
                    ];
                }

                // chunked like markSeen(): one request may carry WorkflowTaskController::MAX_CHECK_KEYS keys
                foreach (array_chunk($inserts, self::INSERT_CHUNK_ROWS) as $chunk) {
                    try {
                        WorkflowTaskRead::insert($chunk);
                    } catch (QueryException $ex) {
                        // another tab of the same user marked one of these between the SELECT and
                        // the INSERT and hit the unique index: the slow, conflict-free path
                        foreach ($chunk as $insert) {
                            WorkflowTaskRead::updateOrCreate([
                                'target_user_id'  => $insert['target_user_id'],
                                'custom_table_id' => $insert['custom_table_id'],
                                'morph_id'        => $insert['morph_id'],
                            ], ['hidden_flg' => true]);
                        }
                    }
                }

                $hidden += count($ids);
            }
        }

        if ($hidden > 0) {
            // exactly the numbers this changes, and the cached navbar payload with them
            $this->unseenCounts = null;
            $this->allCounts = null;
            static::navbarCacheForget();
        }

        return $hidden;
    }

    /**
     * Put the SELECTED tasks back on the current user's list - the way back from hideSelected().
     *
     * Found in review: a task taken off the list came back only with the next action on its
     * record. One that returned to the user without an action - assigned away and back by editing
     * the record - stayed out of sight, while the record page asked them to act. The 削除済み filter
     * (SEEN_REMOVED) shows such tasks; this takes their mark away, so they are back as unread:
     * counted by the badge and printed bold, like a task the user never opened.
     *
     * The keys come from the browser, so each one is checked the way hideSelected() checks them:
     * only this user's own tasks that are on the 削除済み list are touched.
     *
     * @param array<string> $taskKeys
     * @return int how many tasks were put back
     */
    public function restoreSelected(array $taskKeys): int
    {
        // nothing selected: answer without asking the database at all
        if (empty($taskKeys)) {
            return 0;
        }

        // the 削除済み list, whatever filter this instance was built with
        $removed = new self(['seen' => self::SEEN_REMOVED]);
        $tables = $removed->workflowCustomTables();
        $userId = \Exment::getUserId();
        $restored = 0;

        foreach (self::keysByTable($taskKeys) as $customTableId => $morphIds) {
            // not a workflow table this user may see at all: every one of its keys is dropped
            $custom_table = $tables->get($customTableId);
            if (is_nullorempty($custom_table)) {
                continue;
            }

            $tableName = getDBTableName($custom_table);

            foreach (array_chunk(array_values($morphIds), 1000) as $chunk) {
                $ids = $removed->pendingQuery($custom_table, false)
                    ->distinct()
                    ->whereIn($tableName . '.id', $chunk)
                    ->pluck($tableName . '.id')
                    ->all();

                if (empty($ids)) {
                    continue;
                }

                // no mark at all is what an unread task has
                $restored += (int)WorkflowTaskRead::where('target_user_id', $userId)
                    ->where('custom_table_id', $customTableId)
                    ->whereIn('morph_id', $ids)
                    ->where('hidden_flg', true)
                    ->delete();
            }
        }

        if ($restored > 0) {
            // the numbers this changes, and the cached navbar payload with them
            $this->unseenCounts = null;
            $this->allCounts = null;
            static::navbarCacheForget();
        }

        return $restored;
    }

    /**
     * Nobody has executed an action on the record since $moment: its task is still the one a list
     * drawn at that moment showed.
     *
     * Every action writes a workflow value (WorkflowAction::forwardWorkflowValue()), so this is a
     * NOT EXISTS on workflow_values. ">=" and not ">": created_at holds whole seconds, and an action
     * in the very second the list was drawn may not be on it. Refusing such a task costs a reload;
     * letting it through would hide a task the user has not seen.
     *
     * @param \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query
     * @param CustomTable $custom_table
     * @param string $tableName
     * @param \Carbon\Carbon $moment
     * @return void
     */
    private function whereNoActionSince($query, CustomTable $custom_table, string $tableName, \Carbon\Carbon $moment): void
    {
        $valueTable = SystemTableName::WORKFLOW_VALUE;

        $query->whereNotExists(function ($sub) use ($valueTable, $custom_table, $tableName, $moment) {
            $sub->selectRaw('1')
                ->from($valueTable)
                ->whereColumn($valueTable . '.morph_id', $tableName . '.id')
                ->where($valueTable . '.morph_type', $custom_table->table_name)
                ->where($valueTable . '.created_at', '>=', $moment);
        });
    }

    /**
     * A "Y-m-d H:i:s" moment in the application time zone, or null. Only a real one survives -
     * the same rule normalizeDate() applies to a date.
     *
     * @param mixed $value
     * @return \Carbon\Carbon|null
     */
    private static function normalizeMoment($value): ?\Carbon\Carbon
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value)) {
            return null;
        }

        try {
            $moment = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $value);
        } catch (\Throwable $ex) {
            return null;
        }

        // createFromFormat() rolls "2026-02-31" over into March instead of refusing it
        return $moment instanceof \Carbon\Carbon && $moment->format('Y-m-d H:i:s') === $value ? $moment : null;
    }

    /**
     * Parse task keys into "custom_table_id => [morph_id => morph_id]". A malformed key is
     * skipped, it never aborts the batch.
     *
     * @param array<mixed> $taskKeys
     * @return array<int, array<int, int>>
     */
    private static function keysByTable(array $taskKeys): array
    {
        $byTable = [];
        foreach (array_unique($taskKeys) as $taskKey) {
            $parsed = static::parseTaskKey($taskKey);
            if (is_nullorempty($parsed)) {
                continue;
            }
            $byTable[$parsed[0]][$parsed[1]] = $parsed[1];
        }

        return $byTable;
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
        // these counts are exactly what this changes, and so is the cached navbar payload (moved
        // once the marks are gone, see navbarCacheForget())
        $this->unseenCounts = null;
        $this->allCounts = null;

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
            // work user of every record of a table, and an unbounded IN list fails as a whole.
            // Read marks only: a task taken off the list is not "read", and unreading must not
            // bring it back.
            foreach (array_chunk($ids, 1000) as $chunk) {
                $removed += WorkflowTaskRead::where('target_user_id', $userId)
                    ->where('custom_table_id', $customTableId)
                    ->whereIn('morph_id', $chunk)
                    ->where('hidden_flg', false)
                    ->delete();
            }
        }

        static::navbarCacheForget();

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
