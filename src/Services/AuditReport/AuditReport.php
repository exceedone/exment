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
use Exceedone\Exment\Model\FileShareLink;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\PasswordHistory;
use Exceedone\Exment\Model\PermissionChangeLog;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\UserApiKey;
use Exceedone\Exment\Model\UserSetting;
use Exceedone\Exment\Services\AuthUserOrgHelper;
use Exceedone\Exment\Services\LogManagement\LogCatalog;
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
    public const SECTIONS = ['users', 'inactive', 'administrators', 'role_group_permissions', 'role_group_members', 'permission_changes', 'logins', 'login_failures', 'lockouts', 'new_ips', 'api_keys', 'file_shares', 'public_forms', 'log_retention'];

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
            ? static::text('note.' . $key, ['days' => $this->inactiveDays, 'count' => static::FAILURES_LISTED, 'long' => static::LONG_KEY_DAYS])
            : null;
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
        }, static::SECTIONS);
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
                return ['created_at', 'changed_user', 'role_group', 'change', 'kind', 'permission_target', 'permissions', 'source'];
            case 'logins':
                return ['result', 'reason', 'count'];
            case 'login_failures':
                return ['user_code', 'user_name', 'failures', 'last_failure', 'ips'];
            case 'lockouts':
                return ['created_at', 'user_code', 'user_name', 'ip'];
            case 'new_ips':
                return ['created_at', 'user_code', 'user_name', 'ip', 'provider'];
            case 'api_keys':
                return ['user_code', 'user_name', 'name', 'key_prefix', 'scopes', 'tables', 'allowed_ips', 'created_at', 'expires_at', 'last_used', 'use_count', 'state', 'marks'];
            case 'file_shares':
                return ['kind', 'workspace', 'target', 'created_user', 'created_at', 'expires_at', 'password', 'download', 'access', 'last_accessed', 'state', 'marks'];
            case 'public_forms':
                return ['table', 'form', 'state', 'period', 'proxy_user', 'created_at'];
            case 'log_retention':
                return ['log', 'retention', 'archive', 'count'];
        }
        return [];
    }

    // the sections ---------------------------------------------------------------------------

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
        $rows = [];
        $logs = PermissionChangeLog::query()
            ->where('created_at', '>=', $this->from)
            ->where('created_at', '<', $this->until)
            ->orderBy('id')
            ->limit(static::MAX_LOG_ROWS)
            ->get();
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
        foreach ([LoginLog::RESULT_SUCCESS, LoginLog::RESULT_FAILURE, LoginLog::RESULT_LOCKOUT, LoginLog::RESULT_LOGOUT] as $result) {
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
     * @return array{0: array<int, string>, 1: array<int, string>}
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
            return [array_values($permissions), []];
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
        return [array_values($permissions), $groups];
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
