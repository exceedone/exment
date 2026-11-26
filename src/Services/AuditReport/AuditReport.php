<?php

namespace Exceedone\Exment\Services\AuditReport;

use Carbon\Carbon;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\UserSetting as UserSettingEnum;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\DataAccessDaily;
use Exceedone\Exment\Model\DataAccessLog;
use Exceedone\Exment\Model\FileShareLink;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\PasswordHistory;
use Exceedone\Exment\Model\PermissionChangeLog;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\UserApiKey;
use Exceedone\Exment\Model\UserSetting;
use Exceedone\Exment\Services\AuthUserOrgHelper;
use Exceedone\Exment\Services\LogManagement\DataAccessLogs;
use Exceedone\Exment\Services\LogManagement\LogCatalog;
use Exceedone\Exment\Services\LogManagement\LogSettings;
use Exceedone\Exment\Services\SystemRequire\Security\SecurityCheck;
use Exceedone\Exment\Enums\SystemRequireResult;
use Illuminate\Support\Facades\Schema;

/**
 * The IT audit report (ISS02840): what an audit (ISMS, ...) asks of the accounts, the logins, the
 * API keys and what is given outside, for a period. Each section is a title, its columns and its
 * rows of texts, the same for the page, the CSV and the PDF. Nothing more of a person than the
 * logs and the accounts keep: the user code and the name, no mail address; the IP addresses as the
 * log management keeps them (they may be truncated or hashed).
 */
class AuditReport
{
    public const SECTIONS = ['users', 'inactive', 'administrators', 'role_group_permissions', 'role_group_members', 'permission_changes', 'user_table_access', 'table_access', 'administrator_changes', 'logins', 'login_failures', 'lockouts', 'new_ips', 'ip_exemptions', 'ip_exempt_access', 'api_keys', 'file_shares', 'public_forms', 'data_access', 'data_access_heavy', 'mcp_calls', 'security', 'log_retention'];

    /** The sections shown only while their log is kept: the MCP calls (ISS02915). */
    public const OPTIONAL_SECTIONS = ['mcp_calls'];

    /** A user reads unusually much: more than HEAVY_FACTOR times the median of the users, and HEAVY_MIN times or more. */
    public const HEAVY_FACTOR = 10;
    public const HEAVY_MIN = 100;

    /** @var int|null the table of the sections of the data (ISS02905) */
    protected $dataTableId = null;

    /** @var int|null the user of the sections of the data (base user id) */
    protected $dataUserId = null;

    /** @var array<int, array{user: int|null, table: int, kind: string, count: int}>|null */
    protected $dataCounts = null;

    /** Days without a login after which an account is "not used" (the default). */
    public const DEFAULT_INACTIVE_DAYS = 90;

    /** The failures of a user in the period from which it is listed. */
    public const FAILURES_LISTED = 3;

    /** The most rows of a section that lists records of the logs. */
    public const MAX_LOG_ROWS = 10000;

    /** An API key that ends later than this many days from its making is marked. */
    public const LONG_KEY_DAYS = 365;

    /** @var Carbon the first moment of the period */
    protected $from;

    /** @var Carbon the moment after the period */
    protected $until;

    /** @var int */
    protected $inactiveDays;

    /** @var Carbon */
    protected $now;

    /** @var array<int, array<string, mixed>>|null the accounts, once */
    protected $accounts;

    public function __construct(Carbon $from, Carbon $to, int $inactiveDays = self::DEFAULT_INACTIVE_DAYS, ?Carbon $now = null)
    {
        $this->from = $from->copy()->startOfDay();
        $this->until = $to->copy()->addDay()->startOfDay();
        $this->inactiveDays = max(1, $inactiveDays);
        $this->now = $now ?? Carbon::now();
    }

    /**
     * The quarter before the one of $now: the default period.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function previousQuarter(?Carbon $now = null): array
    {
        $start = ($now ?? Carbon::now())->copy()->firstOfQuarter()->subMonthsNoOverflow(3)->startOfDay();
        return [$start, $start->copy()->addMonthsNoOverflow(3)->subDay()];
    }

    public function from(): Carbon
    {
        return $this->from->copy();
    }

    /** The last day of the period. */
    public function to(): Carbon
    {
        return $this->until->copy()->subDay();
    }

    public function inactiveDays(): int
    {
        return $this->inactiveDays;
    }

    /**
     * Since when the login records are kept (null: none yet).
     */
    public function loginLogSince(): ?Carbon
    {
        if (!LoginLog::tableExists()) {
            return null;
        }
        $first = LoginLog::query()->min('created_at');
        return is_null($first) ? null : Carbon::parse($first);
    }

    /**
     * A section: its title, columns (key => label) and rows (each a list of texts in the order of
     * the columns).
     *
     * @return array{key: string, title: string, note: string|null, columns: array<string, string>, rows: array<int, array<int, string>>}
     */
    public function section(string $key): array
    {
        if (!in_array($key, static::SECTIONS, true)) {
            throw new \InvalidArgumentException($key);
        }
        $rows = $this->{'rows' . str_replace('_', '', ucwords($key, '_'))}();
        $columns = [];
        foreach ($this->columnKeys($key) as $column) {
            $columns[$column] = static::text('column.' . $column);
        }
        $note = \Lang::has('exment::exment.audit_report.note.' . $key)
            ? static::text('note.' . $key, ['days' => $this->inactiveDays, 'count' => static::FAILURES_LISTED, 'long' => static::LONG_KEY_DAYS, 'factor' => static::HEAVY_FACTOR, 'min' => static::HEAVY_MIN])
            : null;
        // the reads are not recorded when the operation log keeps the changes only (ISS02905)
        if (in_array($key, ['data_access', 'data_access_heavy'], true) && !LogSettings::logsMethod('GET')) {
            $note = trim(strval($note) . ' ' . static::text('note.reads_not_recorded'));
        }
        return [
            'key' => $key,
            'title' => static::text('section.' . $key),
            'note' => $note,
            'columns' => $columns,
            'rows' => array_map(function ($row) {
                return array_map('strval', array_values($row));
            }, $rows),
        ];
    }

    /**
     * @return array<int, array{key: string, title: string, note: string|null, columns: array<string, string>, rows: array<int, array<int, string>>}>
     */
    public function sections(): array
    {
        return array_map(function ($key) {
            return $this->section($key);
        }, static::sectionKeys());
    }

    /**
     * The sections of the report now: the MCP calls only while the MCP log is used.
     *
     * @return array<int, string>
     */
    public static function sectionKeys(): array
    {
        return array_values(array_filter(static::SECTIONS, function ($key) {
            if ($key === 'mcp_calls') {
                return \Exceedone\Exment\Services\LogManagement\McpLogs::active();
            }
            return true;
        }));
    }

    /**
     * @return array<int, string>
     */
    protected function columnKeys(string $key): array
    {
        switch ($key) {
            case 'users':
                return ['user_code', 'user_name', 'login_type', 'mfa', 'last_login', 'password_changed', 'created_at', 'system_permissions'];
            case 'inactive':
                return ['user_code', 'user_name', 'login_type', 'last_login', 'created_at'];
            case 'administrators':
                return ['user_code', 'user_name', 'system_permissions', 'role_groups', 'mfa', 'last_login'];
            case 'role_group_permissions':
                return ['role_group', 'role_type', 'permission_target', 'permissions'];
            case 'role_group_members':
                return ['role_group', 'member_type', 'member_code', 'member_name'];
            case 'permission_changes':
            case 'administrator_changes':
                return ['created_at', 'changed_user', 'role_group', 'change', 'kind', 'permission_target', 'permissions', 'source'];
            case 'user_table_access':
                return ['user_code', 'user_name', 'table', 'permission', 'can_edit', 'sources'];
            case 'table_access':
                return ['table', 'editors', 'viewers', 'all_users', 'by_permission'];
            case 'logins':
                return ['result', 'reason', 'count'];
            case 'login_failures':
                return ['user_code', 'user_name', 'failures', 'last_failure', 'ips'];
            case 'lockouts':
                return ['created_at', 'user_code', 'user_name', 'ip'];
            case 'new_ips':
                return ['created_at', 'user_code', 'user_name', 'ip', 'provider'];
            case 'ip_exemptions':
                return ['member_type', 'member_code', 'member_name'];
            case 'ip_exempt_access':
                return ['created_at', 'user_code', 'user_name', 'ip'];
            case 'api_keys':
                return ['user_code', 'user_name', 'name', 'key_prefix', 'scopes', 'tables', 'allowed_ips', 'created_at', 'expires_at', 'last_used', 'use_count', 'state', 'marks'];
            case 'file_shares':
                return ['kind', 'workspace', 'target', 'created_user', 'created_at', 'expires_at', 'password', 'download', 'access', 'last_accessed', 'state', 'marks'];
            case 'public_forms':
                return ['table', 'form', 'state', 'period', 'proxy_user', 'created_at'];
            case 'data_access':
                return ['table', 'user_code', 'user_name', 'read', 'list', 'export', 'create', 'update', 'delete', 'restore'];
            case 'data_access_heavy':
                return ['user_code', 'user_name', 'reads', 'median', 'times'];
            case 'mcp_calls':
                return ['user_code', 'user_name', 'mcp_calls', 'mcp_errors', 'mcp_refused', 'mcp_tools', 'first_call', 'last_call'];
            case 'security':
                return ['item', 'result', 'detail'];
            case 'log_retention':
                return ['log', 'retention', 'archive', 'count'];
        }
        return [];
    }

    // the sections ---------------------------------------------------------------------------

    /** @var PermissionMatrix|null */
    protected $permissionMatrix = null;

    protected function permissionMatrix(): PermissionMatrix
    {
        if (!isset($this->permissionMatrix)) {
            $ids = array_values(array_unique(array_map('intval', array_filter(array_column($this->accounts(), 'base_user_id'), 'is_numeric'))));
            $admins = collect(System::system_admin_users() ?? [])->map(function ($id) {
                return strval($id);
            })->all();
            $this->permissionMatrix = new PermissionMatrix($ids, $admins);
        }
        return $this->permissionMatrix;
    }

    /**
     * Who may read and change the data of which table, and from which role group or
     * organization (ISS02911). Every table: one row.
     *
     * @return array<int, array<int, string>>
     */
    protected function rowsUserTableAccess(): array
    {
        $matrix = $this->permissionMatrix()->matrix();
        $rows = [];
        foreach ($this->accounts() as $account) {
            $userId = intval($account['base_user_id']);
            if (isset($this->dataUserId) && $userId !== $this->dataUserId) {
                continue;
            }
            $tables = $matrix[$userId] ?? [];
            ksort($tables);
            foreach ($tables as $tableId => $cell) {
                if (isset($this->dataTableId) && $tableId !== PermissionMatrix::ALL_TABLES && $tableId !== $this->dataTableId) {
                    continue;
                }
                $rows[] = [
                    $account['user_code'],
                    $account['user_name'],
                    $tableId === PermissionMatrix::ALL_TABLES ? static::text('permission_list.all_tables') : $this->tableName($tableId),
                    static::permissionText($cell['level']),
                    $this->yesNo(PermissionMatrix::canEdit($cell['level'])),
                    implode(', ', $cell['sources']),
                ];
                if (count($rows) >= static::MAX_LOG_ROWS) {
                    return $rows;
                }
            }
        }
        return $rows;
    }

    /**
     * Each table: how many may change and read its data, by the permission, and its settings for
     * every user.
     *
     * @return array<int, array<int, string>>
     */
    protected function rowsTableAccess(): array
    {
        $matrix = $this->permissionMatrix()->matrix();
        $rows = [];
        $tables = CustomTable::allRecords();
        foreach ($tables as $table) {
            $tableId = intval($table->id);
            if (isset($this->dataTableId) && $tableId !== $this->dataTableId) {
                continue;
            }
            $editors = 0;
            $viewers = 0;
            $levels = [];
            foreach ($matrix as $cells) {
                $cell = $cells[$tableId] ?? null;
                $all = $cells[PermissionMatrix::ALL_TABLES] ?? null;
                // the stronger of the table and of every table
                if (isset($all) && (is_null($cell) || array_search($all['level'], PermissionMatrix::LEVELS, true) < array_search($cell['level'], PermissionMatrix::LEVELS, true))) {
                    $cell = $all;
                }
                if (is_null($cell)) {
                    continue;
                }
                $levels[$cell['level']] = ($levels[$cell['level']] ?? 0) + 1;
                if (PermissionMatrix::canEdit($cell['level'])) {
                    $editors++;
                }
                if (PermissionMatrix::canView($cell['level'])) {
                    $viewers++;
                }
            }
            $flags = [];
            foreach (['all_user_editable_flg', 'all_user_viewable_flg', 'all_user_accessable_flg'] as $flag) {
                if (boolval($table->getOption($flag))) {
                    $flags[] = static::text('permission_list.' . $flag);
                }
            }
            $by = [];
            foreach (PermissionMatrix::LEVELS as $level) {
                if (isset($levels[$level])) {
                    $by[] = static::permissionText($level) . ': ' . $levels[$level];
                }
            }
            $rows[] = [strval($table->table_view_name), strval($editors), strval($viewers), implode(', ', $flags), implode(', ', $by)];
        }
        return $rows;
    }

    /**
     * The changes of the administrators: in the month up to the end of the report, the changes of
     * the permissions of the system, and of the members of the groups that have one.
     *
     * @return array<int, array<int, string>>
     */
    protected function rowsAdministratorChanges(): array
    {
        if (!PermissionChangeLog::tableExists()) {
            return [];
        }
        $systemGroups = RoleGroupPermission::query()->where('role_group_permission_type', RoleType::SYSTEM)->pluck('role_group_id')->unique()->all();
        $logs = PermissionChangeLog::query()
            ->where('created_at', '>=', $this->until->copy()->subMonthNoOverflow())
            ->where('created_at', '<', $this->until)
            ->where(function ($query) use ($systemGroups) {
                $query->where(function ($q) {
                    $q->where('kind', PermissionChangeLog::KIND_PERMISSION)->where('role_type', strval(RoleType::SYSTEM));
                })->orWhere(function ($q) use ($systemGroups) {
                    $q->where('kind', PermissionChangeLog::KIND_MEMBER)->whereIn('role_group_id', $systemGroups);
                });
            })
            ->orderBy('id')
            ->limit(static::MAX_LOG_ROWS)
            ->get();
        return $this->changeRows($logs);
    }

    protected function tableName(int $tableId): string
    {
        $table = CustomTable::getEloquent($tableId);
        return isset($table) ? strval($table->table_view_name) : '#' . $tableId;
    }

    /**
     * The sections of the data: only a table, only a user (null: all).
     */
    public function filterData(?int $tableId, ?int $userId): self
    {
        $this->dataTableId = $tableId;
        $this->dataUserId = $userId;
        $this->dataCounts = null;
        return $this;
    }

    /**
     * @return array{table: int|null, user: int|null}
     */
    public function dataFilter(): array
    {
        return ['table' => $this->dataTableId, 'user' => $this->dataUserId];
    }

    /**
     * The reads and the writes of the period (ISS02905): the counts of each day, and the rows of
     * the days not counted yet. Not the rows one by one: the report stays quick.
     *
     * @return array<int, array{user: int|null, table: int, kind: string, count: int}>
     */
    protected function dataCounts(): array
    {
        if (isset($this->dataCounts)) {
            return $this->dataCounts;
        }
        $this->dataCounts = [];
        if (!DataAccessLog::tableExists()) {
            return $this->dataCounts;
        }
        $filter = function ($query) {
            if (isset($this->dataTableId)) {
                $query->where('custom_table_id', $this->dataTableId);
            }
            if (isset($this->dataUserId)) {
                $query->where('base_user_id', $this->dataUserId);
            }
            return $query;
        };
        $counted = DataAccessLogs::summarizedUntil();
        // the raw rows from the day after the last day counted
        $rawFrom = $this->from->copy();
        if (isset($counted) && $counted->gte($this->from)) {
            $daily = $filter(DataAccessDaily::query())
                ->where('date', '>=', $this->from->format('Y-m-d'))
                ->where('date', '<=', ($counted->lt($this->until) ? $counted : $this->until->copy()->subDay())->format('Y-m-d'))
                ->selectRaw('base_user_id, custom_table_id, kind, sum(count) as total')
                ->groupBy('base_user_id', 'custom_table_id', 'kind')
                ->get();
            foreach ($daily as $row) {
                $this->addCount($row->base_user_id, intval($row->custom_table_id), strval($row->kind), intval($row->total));
            }
            $rawFrom = $counted->copy()->addDay();
        }
        if ($rawFrom->lt($this->until)) {
            $raw = $filter(DataAccessLog::query())
                ->where('created_at', '>=', $rawFrom)
                ->where('created_at', '<', $this->until)
                ->selectRaw('base_user_id, custom_table_id, kind, count(*) as total')
                ->groupBy('base_user_id', 'custom_table_id', 'kind')
                ->get();
            foreach ($raw as $row) {
                $this->addCount($row->base_user_id, intval($row->custom_table_id), strval($row->kind), intval($row->total));
            }
        }
        return $this->dataCounts;
    }

    /**
     * @param mixed $userId
     */
    protected function addCount($userId, int $tableId, string $kind, int $count): void
    {
        $this->dataCounts[] = ['user' => is_null($userId) ? null : intval($userId), 'table' => $tableId, 'kind' => $kind, 'count' => $count];
    }

    /**
     * The reads and the writes of each table by each user.
     *
     * @return array<int, array<int, string>>
     */
    protected function rowsDataAccess(): array
    {
        $kinds = ['read', 'list', 'export', 'create', 'update', 'delete', 'restore'];
        $cells = [];
        foreach ($this->dataCounts() as $count) {
            $key = $count['table'] . '|' . strval($count['user']);
            if (!isset($cells[$key])) {
                $cells[$key] = ['table' => $count['table'], 'user' => $count['user'], 'total' => 0] + array_fill_keys($kinds, 0);
            }
            $kind = $count['kind'] === DataAccessLog::KIND_FORCE_DELETE ? DataAccessLog::KIND_DELETE : $count['kind'];
            if (isset($cells[$key][$kind])) {
                $cells[$key][$kind] += $count['count'];
                $cells[$key]['total'] += $count['count'];
            }
        }
        usort($cells, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });
        $rows = [];
        foreach (array_slice($cells, 0, static::MAX_LOG_ROWS) as $cell) {
            $table = CustomTable::getEloquent($cell['table']);
            [$code, $name] = is_null($cell['user']) ? ['', static::text('made_by_job')] : $this->userOf($cell['user']);
            $row = [isset($table) ? strval($table->table_view_name) : '#' . $cell['table'], $code, $name];
            foreach ($kinds as $kind) {
                $row[] = number_format($cell[$kind]);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * The users who read unusually much in the period (many reads at once may be a leak).
     *
     * @return array<int, array<int, string>>
     */
    protected function rowsDataAccessHeavy(): array
    {
        $reads = [];
        foreach ($this->dataCounts() as $count) {
            if (is_null($count['user']) || !in_array($count['kind'], DataAccessLog::READS, true)) {
                continue;
            }
            $reads[$count['user']] = ($reads[$count['user']] ?? 0) + $count['count'];
        }
        if (count($reads) === 0) {
            return [];
        }
        $sorted = array_values($reads);
        sort($sorted);
        $middle = intdiv(count($sorted), 2);
        $median = count($sorted) % 2 === 1 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
        arsort($reads);
        $rows = [];
        foreach ($reads as $userId => $total) {
            if ($total < static::HEAVY_MIN || $total <= static::HEAVY_FACTOR * $median) {
                continue;
            }
            [$code, $name] = $this->userOf($userId);
            $rows[] = [$code, $name, number_format($total), number_format($median, 1), $median > 0 ? number_format($total / $median, 1) : '-'];
        }
        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    protected function rowsUsers(): array
    {
        return array_map(function ($a) {
            return [$a['user_code'], $a['user_name'], $a['login_type'], $a['mfa'], $this->date($a['last_login']), $this->date($a['password_changed']), $this->date($a['created_at']), implode(', ', $a['system_permissions'])];
        }, $this->accounts());
    }

    /**
     * @return array<int, array<int, string>>
     */
    protected function rowsInactive(): array
    {
        $limit = $this->now->copy()->subDays($this->inactiveDays);
        $rows = [];
        foreach ($this->accounts() as $a) {
            $last = $a['last_login'] ?? $a['created_at'];
            if (isset($last) && $last->gte($limit)) {
                continue;
            }
            $rows[] = [$a['user_code'], $a['user_name'], $a['login_type'], $this->date($a['last_login']), $this->date($a['created_at'])];
        }
        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    protected function rowsAdministrators(): array
    {
        $rows = [];
        foreach ($this->accounts() as $a) {
            if (count($a['system_permissions']) === 0) {
                continue;
            }
            $rows[] = [$a['user_code'], $a['user_name'], implode(', ', $a['system_permissions']), implode(', ', $a['role_groups']), $a['mfa'], $this->date($a['last_login'])];
        }
        return $rows;
    }

    /**
     * What each role group gives, on what (the system, a table, a plugin), as it is now.
     *
     * @return array<int, array<int, string>>
     */
    protected function rowsRoleGroupPermissions(): array
    {
        $rows = [];
        foreach (RoleGroup::query()->with('role_group_permissions')->orderBy('id')->get() as $group) {
            $permissions = $group->role_group_permissions->sortBy(function ($permission) {
                return strval($permission->role_group_permission_type) . ':' . str_pad(strval($permission->role_group_target_id), 10, '0', STR_PAD_LEFT);
            });
            foreach ($permissions as $permission) {
                $keys = array_values(array_filter((array)($permission->permissions ?? [])));
                if (count($keys) === 0) {
                    continue;
                }
                $type = strval($permission->role_group_permission_type);
                $rows[] = [
                    strval($group->role_group_view_name),
                    $this->roleTypeText($type),
                    $this->targetText($type, $permission->role_group_target_id),
                    implode(', ', array_map([static::class, 'permissionText'], $keys)),
                ];
            }
        }
        return $rows;
    }

    /**
     * The members of each role group (users and organizations), as they are now.
     *
     * @return array<int, array<int, string>>
     */
    protected function rowsRoleGroupMembers(): array
    {
        $rows = [];
        foreach (RoleGroup::query()->with('role_group_user_organizations')->orderBy('id')->get() as $group) {
            $members = $group->role_group_user_organizations->sortBy(function ($member) {
                return strval($member->role_group_user_org_type) . ':' . str_pad(strval($member->role_group_target_id), 10, '0', STR_PAD_LEFT);
            });
            foreach ($members as $member) {
                $type = strval($member->role_group_user_org_type);
                [$code, $name] = $this->memberOf($type, $member->role_group_target_id);
                $rows[] = [strval($group->role_group_view_name), static::text('member_type.' . $type), $code, $name];
            }
        }
        return $rows;
    }

    /**
     * The changes of the role groups in the period (the history begins with 7.3).
     *
     * @return array<int, array<int, string>>
     */
    protected function rowsPermissionChanges(): array
    {
        if (!PermissionChangeLog::tableExists()) {
            return [];
        }
        $logs = PermissionChangeLog::query()
            ->where('created_at', '>=', $this->from)
            ->where('created_at', '<', $this->until)
            ->orderBy('id')
            ->limit(static::MAX_LOG_ROWS)
            ->get();
        return $this->changeRows($logs);
    }

    /**
     * @param iterable<PermissionChangeLog> $logs
     * @return array<int, array<int, string>>
     */
    protected function changeRows(iterable $logs): array
    {
        $rows = [];
        foreach ($logs as $log) {
            [$code, $name] = is_null($log->changed_user_id) ? ['', ''] : $this->userOf($log->changed_user_id);
            if ($log->kind === PermissionChangeLog::KIND_MEMBER) {
                [$memberCode, $memberName] = $this->memberOf(strval($log->target_type), $log->target_id);
                $target = static::text('member_type.' . $log->target_type) . ': ' . trim($memberCode . ' ' . $memberName);
            } elseif ($log->kind === PermissionChangeLog::KIND_PERMISSION) {
                $target = $this->roleTypeText(strval($log->role_type));
                $on = $this->targetText(strval($log->role_type), $log->target_id);
                $target .= $on === '' ? '' : ': ' . $on;
            } else {
                $target = '';
            }
            $rows[] = [
                $this->date($log->created_at, true),
                trim($code . ' ' . $name),
                strval($log->role_group_name),
                static::text('change.' . $log->change),
                static::text('change_kind.' . $log->kind),
                $target,
                is_nullorempty($log->permission) ? '' : static::permissionText(strval($log->permission)),
                static::text('source.' . ($log->source ?: 'page')),
            ];
        }
        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    protected function rowsLogins(): array
    {
        if (!LoginLog::tableExists()) {
            return [];
        }
        $counts = $this->loginQuery()
            ->selectRaw('result, failure_reason, count(*) as aggregate')
            ->groupBy('result', 'failure_reason')
            ->get();
        $rows = [];
        foreach (LoginLog::RESULTS as $result) {
            foreach ($counts->where('result', $result)->sortBy('failure_reason') as $count) {
                $reason = $count->failure_reason;
                $rows[] = [strval(exmtrans('log_management.result.' . $result)), is_nullorempty($reason) ? '' : strval(exmtrans('log_management.reason.' . $reason)), number_format(intval($count->aggregate))];
            }
        }
        return $rows;
    }

    /**
     * The users that failed to log in at least FAILURES_LISTED times in the period, the most first.
     * The failures of names that are no user are one row.
     *
     * @return array<int, array<int, string>>
     */
    protected function rowsLoginFailures(): array
    {
        if (!LoginLog::tableExists()) {
            return [];
        }
        $groups = $this->loginQuery()
            ->where('result', LoginLog::RESULT_FAILURE)
            ->selectRaw('base_user_id, count(*) as aggregate, max(created_at) as last_at')
            ->groupBy('base_user_id')
            ->havingRaw('count(*) >= ?', [static::FAILURES_LISTED])
            ->orderByDesc('aggregate')
            ->limit(static::MAX_LOG_ROWS)
            ->get();
        $rows = [];
        foreach ($groups as $group) {
            $ips = $this->loginQuery()
                ->where('result', LoginLog::RESULT_FAILURE)
                ->where(function ($query) use ($group) {
                    is_null($group->base_user_id) ? $query->whereNull('base_user_id') : $query->where('base_user_id', $group->base_user_id);
                })
                ->whereNotNull('ip')
                ->distinct()
                ->limit(6)
                ->pluck('ip')
                ->all();
            [$code, $name] = $this->userOf($group->base_user_id);
            $rows[] = [
                $code,
                $name,
                number_format(intval($group->aggregate)),
                $this->date($group->last_at, true),
                implode(', ', array_slice($ips, 0, 5)) . (count($ips) > 5 ? ', ...' : ''),
            ];
        }
        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    protected function rowsLockouts(): array
    {
        if (!LoginLog::tableExists()) {
            return [];
        }
        $rows = [];
        foreach ($this->loginQuery()->where('result', LoginLog::RESULT_LOCKOUT)->orderBy('id')->limit(static::MAX_LOG_ROWS)->get() as $log) {
            [$code, $name] = $this->userOf($log->base_user_id);
            $rows[] = [$this->date($log->created_at, true), $code, $name, strval($log->ip)];
        }
        return $rows;
    }

    /**
     * Who the IP filter of the web pages does not refuse (ISS02915): now.
     *
     * @return array<int, array<int, string>>
     */
    protected function rowsIpExemptions(): array
    {
        $settings = \Exceedone\Exment\Services\IpFilterExemptions::settings();
        $rows = [];
        foreach (['users' => SystemTableName::USER, 'organizations' => SystemTableName::ORGANIZATION] as $key => $type) {
            foreach ($settings[$key] as $id) {
                [$code, $name] = $this->memberOf($type, $id);
                $rows[] = [static::text('member_type.' . $type), $code, $name];
            }
        }
        if (count($settings['role_groups']) > 0) {
            $names = RoleGroup::query()->whereIn('id', $settings['role_groups'])->pluck('role_group_view_name', 'id');
            foreach ($settings['role_groups'] as $id) {
                $rows[] = [static::text('member_type.role_group'), '', strval($names[$id] ?? '#' . $id)];
            }
        }
        return $rows;
    }

    /**
     * The uses of the web pages from an address out of the IP filter by a user exempt from it, in
     * the period (once a session and address).
     *
     * @return array<int, array<int, string>>
     */
    protected function rowsIpExemptAccess(): array
    {
        if (!LoginLog::tableExists()) {
            return [];
        }
        $rows = [];
        foreach ($this->loginQuery()->where('result', LoginLog::RESULT_IP_EXEMPT)->orderBy('id')->limit(static::MAX_LOG_ROWS)->get() as $log) {
            [$code, $name] = $this->userOf($log->base_user_id);
            $rows[] = [$this->date($log->created_at, true), $code, $name, strval($log->ip)];
        }
        return $rows;
    }

    /**
     * The first login of a user in the period from an IP address the user had not logged in from
     * before the period.
     *
     * @return array<int, array<int, string>>
     */
    protected function rowsNewIps(): array
    {
        if (!LoginLog::tableExists()) {
            return [];
        }
        $known = [];
        LoginLog::query()
            ->where('result', LoginLog::RESULT_SUCCESS)
            ->where('created_at', '<', $this->from)
            ->whereNotNull('base_user_id')
            ->whereNotNull('ip')
            ->select(['base_user_id', 'ip'])
            ->distinct()
            ->cursor()
            ->each(function ($log) use (&$known) {
                $known[$log->base_user_id . "\n" . $log->ip] = true;
            });

        $rows = [];
        $seen = [];
        $logs = $this->loginQuery()
            ->where('result', LoginLog::RESULT_SUCCESS)
            ->whereNotNull('base_user_id')
            ->whereNotNull('ip')
            ->orderBy('id')
            ->cursor();
        foreach ($logs as $log) {
            $pair = $log->base_user_id . "\n" . $log->ip;
            if (isset($known[$pair]) || isset($seen[$pair])) {
                continue;
            }
            $seen[$pair] = true;
            [$code, $name] = $this->userOf($log->base_user_id);
            $rows[] = [$this->date($log->created_at, true), $code, $name, strval($log->ip), $this->loginTypeText($log->provider, $log->provider_name)];
            if (count($rows) >= static::MAX_LOG_ROWS) {
                break;
            }
        }
        return $rows;
    }

    /**
     * The MCP calls in the period, a user a row (ISS02915): the counts and the tools, never the
     * arguments. The most calls first.
     *
     * @return array<int, array<int, string>>
     */
    protected function rowsMcpCalls(): array
    {
        if (!\Exceedone\Exment\Model\McpLog::tableExists()) {
            return [];
        }
        $groups = \Exceedone\Exment\Model\McpLog::query()
            ->where('created_at', '>=', $this->from)
            ->where('created_at', '<', $this->until)
            ->groupBy('base_user_id', 'tool', 'status')
            ->selectRaw('base_user_id, tool, status, count(*) as calls, min(created_at) as first_at, max(created_at) as last_at')
            ->get();
        $users = [];
        foreach ($groups as $group) {
            $key = strval($group->base_user_id);
            if (!isset($users[$key])) {
                $users[$key] = ['id' => $group->base_user_id, 'calls' => 0, 'errors' => 0, 'refused' => 0, 'tools' => [], 'first' => null, 'last' => null];
            }
            $calls = intval($group->getAttribute('calls'));
            $users[$key]['calls'] += $calls;
            if (in_array($group->status, [\Exceedone\Exment\Model\McpLog::STATUS_ERROR, \Exceedone\Exment\Model\McpLog::STATUS_TOOL_ERROR], true)) {
                $users[$key]['errors'] += $calls;
            } elseif ($group->status === \Exceedone\Exment\Model\McpLog::STATUS_REFUSED) {
                $users[$key]['refused'] += $calls;
            }
            if (!is_nullorempty($group->tool)) {
                $users[$key]['tools'][strval($group->tool)] = true;
            }
            $first = strval($group->getAttribute('first_at'));
            $last = strval($group->getAttribute('last_at'));
            if (!isset($users[$key]['first']) || $first < $users[$key]['first']) {
                $users[$key]['first'] = $first;
            }
            if (!isset($users[$key]['last']) || $last > $users[$key]['last']) {
                $users[$key]['last'] = $last;
            }
        }
        usort($users, function ($a, $b) {
            return $b['calls'] <=> $a['calls'] ?: strcmp(strval($a['id']), strval($b['id']));
        });

        $rows = [];
        foreach ($users as $user) {
            [$code, $name] = $this->userOf($user['id']);
            $tools = array_keys($user['tools']);
            sort($tools);
            $rows[] = [
                $code,
                $name,
                number_format($user['calls']),
                number_format($user['errors']),
                number_format($user['refused']),
                implode(', ', $tools),
                $this->date($user['first'], true),
                $this->date($user['last'], true),
            ];
        }
        return $rows;
    }

    /**
     * The keys that could be used in the period: made before its end and not stopped before its start.
     *
     * @return array<int, array<int, string>>
     */
    protected function rowsApiKeys(): array
    {
        if (!Schema::hasTable('user_api_keys')) {
            return [];
        }
        $keys = UserApiKey::query()
            ->where(function ($query) {
                $query->whereNull('created_at')->orWhere('created_at', '<', $this->until);
            })
            ->where(function ($query) {
                $query->whereNull('revoked_at')->orWhere('revoked_at', '>=', $this->from);
            })
            ->orderBy('user_id')
            ->orderBy('id')
            ->get();
        $rows = [];
        foreach ($keys as $key) {
            [$code, $name] = $this->userOf($key->user_id);
            if (isset($key->revoked_at)) {
                $state = static::text('state.revoked');
            } elseif ($key->expires_at->lte($this->now)) {
                $state = static::text('state.expired');
            } else {
                $state = static::text('state.active');
            }
            $marks = [];
            if ($key->isActive()) {
                if (isset($key->created_at) && $key->expires_at->gt($key->created_at->copy()->addDays(static::LONG_KEY_DAYS))) {
                    $marks[] = static::text('mark.long_expiry');
                }
                $used = $key->last_used_at ?? $key->created_at;
                if (isset($used) && $used->lt($this->now->copy()->subDays($this->inactiveDays))) {
                    $marks[] = static::text('mark.unused', ['days' => $this->inactiveDays]);
                }
                if (count($key->ipList()) === 0) {
                    $marks[] = static::text('mark.any_ip');
                }
            }
            $rows[] = [
                $code,
                $name,
                strval($key->name),
                strval($key->key_prefix),
                implode(', ', (array)$key->scopes),
                is_null($key->tables) ? static::text('all') : strval(count($key->tables)),
                count($key->ipList()) === 0 ? static::text('any') : implode(', ', $key->ipList()),
                $this->date($key->created_at),
                $this->date($key->expires_at),
                $this->date($key->last_used_at),
                number_format(intval($key->use_count)),
                $state,
                implode(', ', $marks),
            ];
        }
        return $rows;
    }

    /**
     * The links of the file server that could be used in the period.
     *
     * @return array<int, array<int, string>>
     */
    protected function rowsFileShares(): array
    {
        if (!Schema::hasTable('file_share_links')) {
            return [];
        }
        $links = FileShareLink::query()
            ->with(['node', 'workspace'])
            ->where(function ($query) {
                $query->whereNull('created_at')->orWhere('created_at', '<', $this->until);
            })
            ->where(function ($query) {
                $query->whereNull('revoked_at')->orWhere('revoked_at', '>=', $this->from);
            })
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', $this->from);
            })
            ->orderBy('id')
            ->get();
        $rows = [];
        foreach ($links as $link) {
            if (isset($link->revoked_at)) {
                $state = static::text('state.revoked');
            } elseif ($link->isExpired()) {
                $state = static::text('state.expired');
            } elseif ($link->isUsedUp()) {
                $state = static::text('state.used_up');
            } else {
                $state = static::text('state.active');
            }
            $marks = [];
            if ($link->isActive()) {
                if (!isset($link->expires_at)) {
                    $marks[] = static::text('mark.no_expiry');
                }
                if (!$link->hasPassword()) {
                    $marks[] = static::text('mark.no_password');
                }
            }
            [, $creator] = $this->userOf($link->created_user_id);
            $rows[] = [
                static::text('kind.' . $link->kind),
                strval(optional($link->workspace)->name),
                strval(optional($link->node)->name),
                $creator,
                $this->date($link->created_at),
                $this->date($link->expires_at),
                $this->yesNo($link->hasPassword()),
                $link->kind === FileShareLink::KIND_SHARE ? $this->yesNo(boolval($link->allow_download)) : '',
                number_format(intval($link->access_count)) . (isset($link->max_access) ? ' / ' . number_format(intval($link->max_access)) : ''),
                $this->date($link->last_accessed_at, true),
                $state,
                implode(', ', $marks),
            ];
        }
        return $rows;
    }

    /**
     * Every public form (a page anyone with its URL may send).
     *
     * @return array<int, array<int, string>>
     */
    protected function rowsPublicForms(): array
    {
        $rows = [];
        foreach (PublicForm::query()->orderBy('id')->get() as $form) {
            $customForm = $form->custom_form;
            $table = isset($customForm) ? CustomTable::getEloquent($customForm->custom_table_id) : null;
            $start = $form->getOption('validity_period_start');
            $end = $form->getOption('validity_period_end');
            $period = (is_nullorempty($start) && is_nullorempty($end)) ? '' : (strval($start) . ' - ' . strval($end));
            $active = boolval($form->active_flg)
                && (is_nullorempty($end) || Carbon::parse($end)->gte($this->now));
            [, $proxy] = $this->userOf($form->proxy_user_id);
            $rows[] = [
                strval(optional($table)->table_view_name),
                strval($form->public_form_view_name),
                static::text($active ? 'state.active' : 'state.inactive'),
                $period,
                $proxy,
                $this->date($form->created_at),
            ];
        }
        return $rows;
    }

    /**
     * The state of the site: the version, the security check (its items of the site, and of the
     * server when they are shown), the last backup.
     *
     * @return array<int, array<int, string>>
     */
    protected function rowsSecurity(): array
    {
        $rows = [];
        $rows[] = [static::text('security.version'), strval(\Exment::version(false)), 'PHP ' . PHP_VERSION];
        try {
            foreach ((new SecurityCheck())->items() as $item) {
                try {
                    $result = strval($item->checkResult());
                    $rows[] = [strval($item->getLabel()), static::text('security.result_' . (in_array($result, [SystemRequireResult::OK, SystemRequireResult::WARNING, SystemRequireResult::NG], true) ? $result : 'other')), strval($item->getMessage())];
                } catch (\Throwable $e) {
                    $rows[] = [strval($item->getLabel()), static::text('security.result_other'), ''];
                }
            }
        } catch (\Throwable $e) {
            // the check could not run here
        }
        $rows[] = [static::text('security.last_backup'), $this->date($this->lastBackup(), true), ''];
        return $rows;
    }

    /**
     * When the last backup was made (the files of the backup), null when there is none.
     */
    protected function lastBackup(): ?Carbon
    {
        try {
            $disk = (new \Exceedone\Exment\Services\BackupRestore\Backup())->disk();
            $last = null;
            foreach ($disk->files('list') as $file) {
                if (!str_ends_with(strtolower($file), '.zip')) {
                    continue;
                }
                $time = intval($disk->lastModified($file));
                $last = max($last ?? 0, $time);
            }
            return is_null($last) ? null : Carbon::createFromTimestamp($last);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The users who may read the report (the system administrators and the users with Audit):
     * base user ids. The job tells them of a report made.
     *
     * @return array<int, int>
     */
    public function auditorIds(): array
    {
        $ids = [];
        foreach ($this->accounts() as $account) {
            if ($account['audit'] && !is_nullorempty($account['base_user_id'])) {
                $ids[intval($account['base_user_id'])] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * @return array<int, array<int, string>>
     */
    protected function rowsLogRetention(): array
    {
        $rows = [];
        foreach (LogCatalog::types() as $type) {
            $rows[] = [
                strval(trans($type['label'])),
                is_null($type['retention_days']) ? strval(exmtrans('log_management.kept')) : strval(exmtrans('log_management.days', ['days' => $type['retention_days']])),
                $this->yesNo($type['archive']),
                is_null($type['count']) ? '' : number_format(intval($type['count'])),
            ];
        }
        return $rows;
    }

    // the accounts ---------------------------------------------------------------------------

    /**
     * Each login account: its user, how it logs in, the second factor, the last login, the last
     * change of the password and its permissions of the system.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function accounts(): array
    {
        if (isset($this->accounts)) {
            return $this->accounts;
        }
        $logins = LoginUser::query()->with('base_user')->orderBy('base_user_id')->orderBy('id')->get();

        $lastLogins = [];
        if (LoginLog::tableExists()) {
            $lastLogins = LoginLog::query()
                ->where('result', LoginLog::RESULT_SUCCESS)
                ->whereNotNull('login_user_id')
                ->groupBy('login_user_id')
                ->selectRaw('login_user_id, max(created_at) as last_at')
                ->pluck('last_at', 'login_user_id')
                ->all();
        }
        $passwords = PasswordHistory::query()
            ->groupBy('login_user_id')
            ->selectRaw('login_user_id, max(created_at) as last_at')
            ->pluck('last_at', 'login_user_id')
            ->all();
        $providers = [];
        foreach (UserSetting::query()->whereIn('base_user_id', $logins->pluck('base_user_id')->filter()->unique()->all())->get() as $setting) {
            $providers[$setting->base_user_id] = array_get($setting->settings ?? [], UserSettingEnum::USER_SETTING . '.login_2factor_provider');
        }

        $admins = collect(System::system_admin_users() ?? [])->map(function ($id) {
            return strval($id);
        })->all();
        $permissionOn = boolval(System::permission_available());
        $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_role_group(), JoinedOrgFilterType::ALL);

        $accounts = [];
        foreach ($logins as $login) {
            $user = $login->base_user;
            $userId = $login->base_user_id;
            [$permissions, $groups] = $this->systemPermissionsOf($userId, $enum, $admins, $permissionOn);
            $last = $lastLogins[$login->id] ?? null;
            $password = $passwords[$login->id] ?? null;
            $accounts[] = [
                'user_code' => strval(array_get($user->value ?? [], 'user_code')),
                'user_name' => strval(array_get($user->value ?? [], 'user_name')),
                'login_type' => $this->loginTypeText($login->login_type ?? null, $login->login_provider),
                'mfa' => $this->mfaText($login, $providers[$userId] ?? null),
                'last_login' => is_null($last) ? null : Carbon::parse($last),
                'password_changed' => is_null($password) ? null : Carbon::parse($password),
                'created_at' => isset($login->created_at) ? Carbon::parse($login->created_at) : null,
                'system_permissions' => $permissions,
                'role_groups' => $groups,
                'base_user_id' => $userId,
                'audit' => isset($permissions[Permission::SYSTEM]) || isset($permissions[Permission::AUDIT]),
            ];
        }
        return $this->accounts = $accounts;
    }

    /**
     * The permissions of the system of a user (through the role groups of the user and of the
     * user's organizations, as the permissions of a request), and those groups.
     *
     * @param mixed $userId
     * @param mixed $enum
     * @param array<int, string> $admins the system administrators
     * @return array{0: array<string, string>, 1: array<int, string>} the permissions (key => label) and the groups
     */
    protected function systemPermissionsOf($userId, $enum, array $admins, bool $permissionOn): array
    {
        if (is_nullorempty($userId)) {
            return [[], []];
        }
        $permissions = [];
        $groups = [];
        if (in_array(strval($userId), $admins, true)) {
            $permissions[Permission::SYSTEM] = static::permissionText(Permission::SYSTEM);
        }
        if (!$permissionOn) {
            // without the permissions, every user may do everything
            $permissions[Permission::SYSTEM] = static::permissionText(Permission::SYSTEM);
            return [$permissions, []];
        }
        $orgIds = AuthUserOrgHelper::getOrganizationIdsForQuery($enum, $userId);
        foreach (RoleGroup::getHasPermissionRoleGroup($userId, $orgIds) as $group) {
            $has = false;
            foreach ($group->role_group_permissions as $groupPermission) {
                if (strval($groupPermission->role_group_permission_type) !== RoleType::SYSTEM || is_null($groupPermission->permissions)) {
                    continue;
                }
                foreach ((array)$groupPermission->permissions as $permission) {
                    $permissions[$permission] = static::permissionText($permission);
                    $has = true;
                }
            }
            if ($has) {
                $groups[] = strval($group->role_group_view_name);
            }
        }
        return [$permissions, $groups];
    }

    /**
     * @param mixed $loginType
     * @param mixed $provider
     */
    protected function loginTypeText($loginType, $provider): string
    {
        $type = is_nullorempty($loginType) ? (is_nullorempty($provider) ? LoginType::PURE : LoginType::OAUTH) : strval($loginType);
        $text = static::text('login_type.' . $type);
        if ($type !== LoginType::PURE && !is_nullorempty($provider)) {
            $text .= ' (' . strval($provider) . ')';
        }
        return $text;
    }

    /**
     * The second factor of an account: the logins with ID and password only; a login of a
     * provider (SSO, LDAP) has the factors of the provider.
     *
     * @param mixed $provider the provider chosen by the user (user setting)
     */
    protected function mfaText(LoginUser $login, $provider): string
    {
        $type = $login->login_type ?? (is_nullorempty($login->login_provider) ? LoginType::PURE : LoginType::OAUTH);
        if (strval($type) !== LoginType::PURE) {
            return static::text('mfa.provider');
        }
        if (!boolval(System::login_use_2factor())) {
            return static::text('mfa.off');
        }
        $provider = is_nullorempty($provider) ? (System::login_2factor_provider() ?? 'email') : $provider;
        if ($provider === 'google') {
            return static::text(boolval($login->auth2fa_available) ? 'mfa.google' : 'mfa.google_pending');
        }
        // a provider of a plugin: its name
        return $provider === 'email' ? static::text('mfa.email') : strval($provider);
    }

    protected function roleTypeText(string $type): string
    {
        switch ($type) {
            case RoleType::SYSTEM:
                return static::text('role_type.system');
            case RoleType::TABLE:
                return static::text('role_type.table');
            case RoleType::PLUGIN:
                return static::text('role_type.plugin');
        }
        return $type;
    }

    /**
     * The table or the plugin of a permission (the system: nothing).
     *
     * @param mixed $targetId
     */
    protected function targetText(string $type, $targetId): string
    {
        if (is_nullorempty($targetId)) {
            return '';
        }
        if ($type === RoleType::TABLE) {
            $table = CustomTable::getEloquent($targetId);
            return isset($table) ? strval($table->table_view_name) : '#' . strval($targetId);
        }
        if ($type === RoleType::PLUGIN) {
            /** @var Plugin|null $plugin */
            $plugin = Plugin::query()->find($targetId);
            return isset($plugin) ? strval($plugin->plugin_view_name) : '#' . strval($targetId);
        }
        return '';
    }

    /**
     * The code and the name of a member (a user or an organization).
     *
     * @param mixed $targetId
     * @return array{0: string, 1: string}
     */
    protected function memberOf(string $type, $targetId): array
    {
        if ($type !== SystemTableName::ORGANIZATION) {
            return $this->userOf($targetId);
        }
        if (is_nullorempty($targetId)) {
            return ['', ''];
        }
        $key = 'organization:' . strval($targetId);
        if (!isset($this->users[$key])) {
            $org = getModelName(SystemTableName::ORGANIZATION)::withTrashed()->find($targetId);
            $this->users[$key] = isset($org)
                ? [strval(array_get($org->value ?? [], 'organization_code')), strval(array_get($org->value ?? [], 'organization_name'))]
                : ['', '#' . strval($targetId)];
        }
        return $this->users[$key];
    }

    /** @var array<string, array{0: string, 1: string}> */
    protected $users = [];

    /**
     * The code and the name of a user (base user id).
     *
     * @param mixed $userId
     * @return array{0: string, 1: string}
     */
    protected function userOf($userId): array
    {
        if (is_nullorempty($userId)) {
            return ['', static::text('unknown_user')];
        }
        $key = strval($userId);
        if (!isset($this->users[$key])) {
            $user = getModelName(SystemTableName::USER)::withTrashed()->find($userId);
            $this->users[$key] = isset($user)
                ? [strval(array_get($user->value ?? [], 'user_code')), strval(array_get($user->value ?? [], 'user_name'))]
                : ['', '#' . $key];
        }
        return $this->users[$key];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<LoginLog>
     */
    protected function loginQuery()
    {
        return LoginLog::query()->where('created_at', '>=', $this->from)->where('created_at', '<', $this->until);
    }

    /**
     * @param mixed $date
     */
    protected function date($date, bool $time = false): string
    {
        if (is_nullorempty($date)) {
            return '';
        }
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        return $date->format($time ? 'Y-m-d H:i' : 'Y-m-d');
    }

    protected function yesNo(bool $value): string
    {
        return static::text($value ? 'yes' : 'no');
    }

    public static function permissionText(string $permission): string
    {
        foreach (['system', 'role_group', 'table', 'master', 'plugin', 'value'] as $group) {
            $key = 'role_group.role_type_option_' . $group . '.' . $permission . '.label';
            if (\Lang::has('exment::exment.' . $key)) {
                return strval(exmtrans($key));
            }
        }
        return $permission;
    }

    /**
     * @param array<string, mixed> $replace
     */
    public static function text(string $key, array $replace = []): string
    {
        return strval(exmtrans('audit_report.' . $key, $replace));
    }
}
