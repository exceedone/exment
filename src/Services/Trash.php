<?php

namespace Exceedone\Exment\Services;

use Carbon\Carbon;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The trash (ISS00334): the deleted rows of every table the user may see the deleted rows of,
 * on one page (TrashController), as the "deleted data" of the list of each table.
 *
 * - The tables: those of the table list (showlist_flg) whose deleted rows the user may see
 *   (CustomTable::enableShowTrashed() and enableView()), as the list of each table.
 * - The rows: of each table, through its model (its own rules of which rows the user reads),
 *   put together with UNION ALL, the newest first, a page at a time (the database sorts and
 *   cuts; only the rows of the page are read in full).
 * - Restore and delete for good: the permission of the list of each table (to edit the table,
 *   and the row), with CustomValue::restore() and forceDelete(): the children, the files, the
 *   revisions, the plugins and the events go as from the list of the table.
 */
class Trash
{
    /** The feature (Features): off until an administrator turns it on. */
    public const FEATURE = 'trash';

    public const PER_PAGE = 30;
    public const MAX_PER_PAGE = 100;

    /** The rows restored or deleted in one request. */
    public const MAX_ITEMS = 100;

    /**
     * The tables whose deleted rows the login user may see.
     *
     * @return Collection<int, CustomTable>
     */
    public static function tables(): Collection
    {
        $tables = [];
        foreach (CustomTable::allRecords() as $table) {
            if (!$table instanceof CustomTable || !boolval($table->showlist_flg)) {
                continue;
            }
            // the rows of a comment or an attachment go with their record
            if (in_array($table->table_name, [SystemTableName::COMMENT, SystemTableName::DOCUMENT], true)) {
                continue;
            }
            if ($table->enableShowTrashed() === true && $table->enableView() === true && hasTable(strval(getDBTableName($table, false)))) {
                $tables[] = $table;
            }
        }
        return collect($tables);
    }

    /**
     * Whether the user may see the deleted rows of a table at least (the menu, the page).
     */
    public static function available(): bool
    {
        $user = \Exment::user();
        if (!isset($user)) {
            return false;
        }
        if ($user->isAdministrator()) {
            return true;
        }
        return $user->hasPermissionContainsTable([Permission::CUSTOM_TABLE, Permission::CUSTOM_VALUE_VIEW_TRASHED]);
    }

    /**
     * The deleted rows, the newest first.
     *
     * @param array{table?: mixed, user?: mixed, from?: mixed, to?: mixed} $filters
     *   table: the id of a table (empty: all), user: the id of the user who deleted,
     *   from / to: days (Y-m-d) of the user
     * @param Collection<int, CustomTable>|null $tables the tables (default: tables())
     * @return LengthAwarePaginator<int, array<string, mixed>>
     *   custom_table, custom_value (null when it is not read), id, deleted_at, deleted_user_id
     */
    public static function paginate(array $filters, int $perPage = self::PER_PAGE, int $page = 1, ?Collection $tables = null): LengthAwarePaginator
    {
        $perPage = max(1, min(static::MAX_PER_PAGE, $perPage));
        $page = max(1, $page);
        $tables = $tables ?? static::tables();
        $tableId = strval($filters['table'] ?? '');
        if ($tableId !== '') {
            $tables = $tables->filter(function ($table) use ($tableId) {
                return strval($table->id) === $tableId;
            })->values();
        }

        $union = null;
        foreach ($tables as $table) {
            $query = static::tableQuery($table, $filters);
            $union = is_null($union) ? $query : $union->unionAll($query);
        }
        if (is_null($union)) {
            return new LengthAwarePaginator([], 0, $perPage, $page);
        }

        $total = DB::query()->fromSub($union, 'trash_rows')->count();
        $rows = DB::query()->fromSub($union, 'trash_rows')
            ->orderBy('deleted_at', 'desc')->orderBy('custom_table_id')->orderBy('id', 'desc')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get();

        // the rows of the page in full, a query for each table on it
        $byId = $tables->keyBy('id');
        $values = [];
        foreach ($rows->groupBy('custom_table_id') as $id => $group) {
            $table = $byId->get($id);
            if (!isset($table)) {
                continue;
            }
            $values[$id] = getModelName($table)::onlyTrashed()->whereIn('id', $group->pluck('id')->all())->get()->keyBy('id');
        }
        $items = $rows->map(function ($row) use ($byId, $values) {
            return [
                'custom_table' => $byId->get($row->custom_table_id),
                'custom_value' => isset($values[$row->custom_table_id]) ? $values[$row->custom_table_id]->get($row->id) : null,
                'id' => intval($row->id),
                'deleted_at' => $row->deleted_at,
                'deleted_user_id' => $row->deleted_user_id,
            ];
        })->filter(function ($item) {
            return isset($item['custom_table']);
        })->values()->all();

        return new LengthAwarePaginator($items, $total, $perPage, $page);
    }

    /**
     * The deleted rows of a table: id, deleted_at, deleted_user_id and the id of the table.
     *
     * @param array<string, mixed> $filters
     * @return \Illuminate\Database\Query\Builder
     */
    protected static function tableQuery(CustomTable $table, array $filters)
    {
        $db = getDBTableName($table);
        $query = getModelName($table)::onlyTrashed()
            ->select([
                DB::raw(intval($table->id) . ' as custom_table_id'),
                "$db.id as id",
                "$db.deleted_at as deleted_at",
                "$db.deleted_user_id as deleted_user_id",
            ]);
        $user = strval($filters['user'] ?? '');
        if ($user !== '' && ctype_digit($user)) {
            $query->where("$db.deleted_user_id", intval($user));
        }
        if (!is_null($from = static::day($filters['from'] ?? null))) {
            $query->where("$db.deleted_at", '>=', UserTimezone::dayStartForQuery($from));
        }
        if (!is_null($to = static::day($filters['to'] ?? null))) {
            $query->where("$db.deleted_at", '<', UserTimezone::dayStartForQuery($to->copy()->addDay()));
        }
        // the query with the rules of the model (the rows the user reads)
        return $query->toBase();
    }

    /**
     * @param mixed $value
     */
    protected static function day($value): ?Carbon
    {
        $value = trim(strval($value ?? ''));
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }
        try {
            $day = Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable $e) {
            return null;
        }
        return $day instanceof Carbon ? $day->startOfDay() : null;
    }

    /**
     * The items of a request ("<table id>:<row id>"), of the tables the user may see the
     * deleted rows of: [[table, id], ...]. Null when one of them is not one.
     *
     * @param mixed $items
     * @param Collection<int, CustomTable>|null $tables the tables (default: tables())
     * @return array<int, array{0: CustomTable, 1: int}>|null
     */
    public static function parseItems($items, ?Collection $tables = null): ?array
    {
        $items = array_values(array_unique(array_filter(array_map('strval', (array)$items))));
        if (count($items) === 0 || count($items) > static::MAX_ITEMS) {
            return null;
        }
        $byId = ($tables ?? static::tables())->keyBy('id');
        $result = [];
        foreach ($items as $item) {
            if (preg_match('/\A(\d+):(\d+)\z/', $item, $m) !== 1) {
                return null;
            }
            $table = $byId->get(intval($m[1]));
            if (!isset($table)) {
                return null;
            }
            $result[] = [$table, intval($m[2])];
        }
        return $result;
    }

    /**
     * Why the user may not restore the row, or delete it for good (the same permission, as the
     * list of the table): null when they may.
     */
    public static function denied(CustomTable $table, ?CustomValue $value): ?string
    {
        if (!isset($value) || !$value->trashed()) {
            return strval(exmtrans('trash.message.not_found'));
        }
        if ($table->enableShowTrashed() !== true || $table->enableEdit() !== true || !$table->hasPermissionEditData($value)) {
            return ErrorCode::PERMISSION_DENY()->getMessage();
        }
        return null;
    }

    /**
     * Restore the rows (all or none).
     *
     * @param array<int, array{0: CustomTable, 1: int}> $items
     * @return string|null the error, null when restored
     */
    public static function restore(array $items): ?string
    {
        return static::run($items, function (CustomTable $table, CustomValue $value) {
            // as the list of the table: not while its parent is in the trash
            $parent = $value->getParentValue(null, true);
            if ($parent instanceof CustomValue && $parent->trashed()) {
                return strval(exmtrans('trash.message.parent_trashed'));
            }
            $value->restore();
            return null;
        });
    }

    /**
     * Delete the rows for good (all or none).
     *
     * @param array<int, array{0: CustomTable, 1: int}> $items
     * @return string|null the error, null when deleted
     */
    public static function forceDelete(array $items): ?string
    {
        return static::run($items, function (CustomTable $table, CustomValue $value) {
            $value->forceDelete();
            return null;
        });
    }

    /**
     * @param array<int, array{0: CustomTable, 1: int}> $items
     */
    protected static function run(array $items, \Closure $action): ?string
    {
        DB::beginTransaction();
        try {
            foreach ($items as [$table, $id]) {
                $value = $table->getValueModel($id, true);
                if (!is_null($error = static::denied($table, $value))) {
                    DB::rollBack();
                    return $error;
                }
                /** @var CustomValue $value */
                if (!is_null($error = $action($table, $value))) {
                    DB::rollBack();
                    return $error;
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return $e->getMessage();
        }
        return null;
    }
}
