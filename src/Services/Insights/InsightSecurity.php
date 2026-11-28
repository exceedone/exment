<?php

namespace Exceedone\Exment\Services\Insights;

use Carbon\Carbon;
use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Model\BackupRun;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\AuditReport\AuditReport;
use Exceedone\Exment\Services\Encryption\DataKeys;
use Exceedone\Exment\Services\PluginDistribution\BundledPlugins;
use Exceedone\Exment\Services\SystemRequire\Security\SecurityBase;
use Exceedone\Exment\Services\SystemRequire\Security\SecurityCheck;

/**
 * The security of the page "Insights" (ISS02964): read from what Exment already checks and keeps
 * (the security check, the IT audit report, the backup runs, the bundled plugins, the data keys),
 * not counted again. No secret is shown: no key, no password, no hash. The names of people only
 * for Audit ($perPerson).
 */
class InsightSecurity
{
    /** An API key not used in this many days is marked. */
    public const UNUSED_DAYS = 90;

    /** The names listed (the most failed logins, the changes of the administrators). */
    public const TOP = 10;

    /** @var Carbon */
    protected $from;

    /** @var Carbon the last day (included) */
    protected $to;

    /** @var bool */
    protected $perPerson;

    /** @var AuditReport|null */
    protected $audit;

    public function __construct(Carbon $from, Carbon $to, bool $perPerson)
    {
        $this->from = $from->copy()->startOfDay();
        $this->to = $to->copy()->startOfDay();
        $this->perPerson = $perPerson;
    }

    /**
     * All the cards, kept 10 minutes (the security check asks the site itself over HTTP).
     *
     * @return array<string, mixed>
     */
    public function cards(): array
    {
        $key = 'exment_insights_security_' . md5($this->from->format('Ymd') . $this->to->format('Ymd') . ($this->perPerson ? 'p' : 't') . app()->getLocale());
        return \Cache::remember($key, 600, function () {
            return [
                'checks' => $this->checks(),
                'logins' => $this->logins(),
                'admins' => $this->admins(),
                'accounts' => $this->accounts(),
                'api' => $this->api(),
                'backup' => $this->backup(),
                'plugins' => $this->plugins(),
                'encryption' => $this->encryption(),
            ];
        });
    }

    protected function audit(): AuditReport
    {
        return $this->audit = $this->audit ?? new AuditReport($this->from, $this->to->copy()->endOfDay(), static::UNUSED_DAYS);
    }

    /**
     * The rows of a section of the IT audit report, each by the keys of its columns.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rows(string $section): array
    {
        try {
            $data = $this->audit()->section($section);
        } catch (\Throwable $e) {
            \Log::warning('[Exment] insights: security: ' . $section . ': ' . $e->getMessage());
            return [];
        }
        $keys = array_keys($data['columns']);
        return array_map(function ($row) use ($keys) {
            return array_combine($keys, array_slice(array_pad(array_values($row), count($keys), ''), 0, count($keys))) ?: [];
        }, $data['rows']);
    }

    /**
     * The security check of Exment (the system settings), as a traffic light: ok, warning, ng.
     *
     * @return array<int, array{label: string, result: string, message: string|null, url: string|null, high: bool}>
     */
    protected function checks(): array
    {
        $out = [];
        try {
            foreach ((new SecurityCheck())->items() as $item) {
                /** @var SecurityBase $item */
                $result = strval($item->checkResult());
                $out[] = [
                    'label' => strval($item->getLabel()),
                    'result' => $result === SystemRequireResult::OK ? 'ok' : ($result === SystemRequireResult::WARNING ? 'warning' : 'ng'),
                    'message' => $result === SystemRequireResult::OK ? null : $item->getMessage(),
                    'url' => $item->getSettingUrl() ?? admin_url('security_check'),
                    'high' => $result !== SystemRequireResult::OK && $item->isHigh(),
                ];
            }
        } catch (\Throwable $e) {
            \Log::warning('[Exment] insights: security check: ' . $e->getMessage());
        }
        // the worst first
        $order = ['ng' => 0, 'warning' => 1, 'ok' => 2];
        usort($out, function ($a, $b) use ($order) {
            return [$order[$a['result']], $a['high'] ? 0 : 1] <=> [$order[$b['result']], $b['high'] ? 0 : 1];
        });
        return $out;
    }

    /**
     * The failed logins and the lockouts: by the day, from how many addresses, the names tried
     * most (Audit); the logins from an address new to the user.
     *
     * @return array<string, mixed>
     */
    protected function logins(): array
    {
        $end = $this->to->copy()->addDay();
        $failures = 0;
        $lockouts = 0;
        $ips = 0;
        $names = [];
        if (LoginLog::tableExists()) {
            $base = function () use ($end) {
                return LoginLog::query()->whereIn('result', [LoginLog::RESULT_FAILURE, LoginLog::RESULT_LOCKOUT])
                    ->where('created_at', '>=', $this->from)->where('created_at', '<', $end);
            };
            $failures = $base()->where('result', LoginLog::RESULT_FAILURE)->count();
            $lockouts = $base()->where('result', LoginLog::RESULT_LOCKOUT)->count();
            $ips = $base()->whereNotNull('ip')->distinct()->count('ip');
            if ($this->perPerson) {
                $names = $base()->whereNotNull('login_name')->selectRaw('login_name, count(*) as total')->groupBy('login_name')
                    ->orderByDesc('total')->limit(static::TOP)->pluck('total', 'login_name')->map(function ($v) {
                        return intval($v);
                    })->all();
            }
        }
        $newIps = $this->rows('new_ips');
        return [
            'failures' => $failures,
            'lockouts' => $lockouts,
            'ips' => $ips,
            'names' => $names,
            'new_places' => count($newIps),
            'new_place_rows' => $this->perPerson ? array_slice($newIps, 0, static::TOP) : [],
            'url' => admin_url('log_management') . '?' . http_build_query(['from' => $this->from->format('Y-m-d'), 'to' => $this->to->format('Y-m-d'), 'result' => 'failure']),
        ];
    }

    /**
     * The administrators (system permissions) and the changes of their permissions in the period.
     *
     * @return array<string, mixed>
     */
    protected function admins(): array
    {
        $changes = $this->rows('administrator_changes');
        return [
            'count' => count($this->rows('administrators')),
            'changes' => count($changes),
            'change_rows' => $this->perPerson ? array_slice(array_reverse($changes), 0, static::TOP) : [],
            'url' => admin_url('role_group'),
            'audit_url' => admin_urls('log_management', 'audit') . '?' . http_build_query(['from' => $this->from->format('Y-m-d'), 'to' => $this->to->format('Y-m-d')]),
        ];
    }

    /**
     * Two-step sign-in and the passwords of the accounts (those who sign in with a password).
     *
     * @return array<string, mixed>
     */
    protected function accounts(): array
    {
        $users = $this->rows('users');
        $mfa = [];
        $neverChanged = 0;
        $expired = 0;
        $days = intval(System::password_expiration_days());
        $pure = 0;
        foreach ($users as $row) {
            $text = strval($row['mfa'] ?? '');
            $mfa[$text] = ($mfa[$text] ?? 0) + 1;
            if ($text === AuditReport::text('mfa.provider')) {
                continue;
            }
            $pure++;
            $changed = strval($row['password_changed'] ?? '');
            if ($changed === '') {
                $neverChanged++;
            } elseif ($days > 0 && Carbon::parse($changed)->lt(Insights::now()->subDays($days))) {
                $expired++;
            }
        }
        arsort($mfa);
        return [
            'users' => count($users),
            'two_factor' => boolval(System::login_use_2factor()),
            'mfa' => $mfa,
            'password_users' => $pure,
            'never_changed' => $neverChanged,
            'expiration_days' => $days,
            'expired' => $expired,
            'url' => admin_url('login_setting'),
        ];
    }

    /**
     * The API keys in use with a mark (a long end date, any address, not used), the refused calls,
     * and the calls of the AI refused.
     *
     * @return array<string, mixed>
     */
    protected function api(): array
    {
        $active = 0;
        $marks = ['long_expiry' => 0, 'any_ip' => 0, 'unused' => 0];
        $texts = [
            'long_expiry' => AuditReport::text('mark.long_expiry'),
            'any_ip' => AuditReport::text('mark.any_ip'),
            'unused' => AuditReport::text('mark.unused', ['days' => static::UNUSED_DAYS]),
        ];
        foreach ($this->rows('api_keys') as $row) {
            if (strval($row['state'] ?? '') !== AuditReport::text('state.active')) {
                continue;
            }
            $active++;
            foreach ($texts as $key => $text) {
                if (strpos(strval($row['marks'] ?? ''), $text) !== false) {
                    $marks[$key]++;
                }
            }
        }
        $mcpRefused = 0;
        foreach ($this->rows('mcp_calls') as $row) {
            $mcpRefused += intval($row['mcp_refused'] ?? 0);
        }
        return ['active' => $active, 'marks' => $marks, 'mcp_refused' => $mcpRefused, 'url' => admin_url('api_keys')];
    }

    /**
     * The backups: the last that ended well (and whether its file has the data of the database),
     * the last that failed and why.
     *
     * @return array<string, mixed>
     */
    protected function backup(): array
    {
        if (!\Schema::hasTable('backup_runs')) {
            return ['last' => null, 'failed' => null, 'url' => admin_url('backup')];
        }
        $last = BackupRun::query()->where('kind', BackupRun::KIND_BACKUP)->whereIn('status', [BackupRun::STATUS_SUCCEEDED, BackupRun::STATUS_WARNING])
            ->orderByDesc('id')->first();
        $failed = BackupRun::query()->where('kind', BackupRun::KIND_BACKUP)->whereIn('status', ['failed', 'stalled'])->orderByDesc('id')->first();
        if (isset($last, $failed) && $failed->id < $last->id && Carbon::parse($failed->created_at)->lt($this->from)) {
            // an old failure, already followed by a good backup
            $failed = null;
        }
        return [
            'last' => isset($last) ? [
                'at' => optional($last->finished_at ?? $last->created_at)->format('Y-m-d H:i'),
                'size' => $last->file_size,
                'warnings' => intval($last->warnings),
                'database' => static::hasDatabase($last),
                'days' => isset($last->finished_at) ? intval($last->finished_at->diffInDays(Insights::now(), true)) : null,
            ] : null,
            'failed' => isset($failed) ? [
                'at' => optional($failed->finished_at ?? $failed->created_at)->format('Y-m-d H:i'),
                'message' => mb_strimwidth(strval($failed->message), 0, 200, '…'),
            ] : null,
            'url' => admin_url('backup'),
        ];
    }

    /**
     * Whether the file of a backup has the data of the database: its definition of the tables
     * is there and not empty (a backup made while mysqldump failed has none). Null: not known
     * (the file is not on a local disk).
     */
    public static function hasDatabase(BackupRun $run): ?bool
    {
        if (!class_exists('ZipArchive') || is_nullorempty($run->file_name)) {
            return null;
        }
        try {
            $path = \Storage::disk(Define::DISKNAME_BACKUP_SYNC)->path(path_join('list', $run->file_name . '.zip'));
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_file($path)) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        $def = strval(config('exment.backup_info.def_file', 'table_definition.sql'));
        $found = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (is_array($stat) && basename(strval($stat['name'])) === $def) {
                $found = intval($stat['size']) > 0;
                break;
            }
        }
        $zip->close();
        return $found;
    }

    /**
     * The plugins not from the official releases (uploaded by hand: their signature is not checked).
     *
     * @return array<string, mixed>
     */
    protected function plugins(): array
    {
        $official = [];
        try {
            foreach (BundledPlugins::rows(false) as $row) {
                $official[strval($row['uuid'] ?? '')] = true;
            }
        } catch (\Throwable $e) {
            // no release package: every plugin is one of the site
        }
        $manual = [];
        foreach (Plugin::query()->get() as $plugin) {
            if (!isset($official[strval($plugin->uuid)])) {
                $manual[] = ['label' => strval($plugin->plugin_view_name ?: $plugin->plugin_name), 'active' => boolval($plugin->active_flg)];
            }
        }
        return ['manual' => $manual, 'url' => admin_url('plugin')];
    }

    /**
     * The keys of the encrypted columns: set or not, and whether keys before a rotation are still
     * there (values not encrypted again yet). Never the keys themselves.
     *
     * @return array<string, mixed>
     */
    protected function encryption(): array
    {
        try {
            return [
                'configured' => DataKeys::configured(),
                'unavailable' => DataKeys::unavailable() !== null,
                'keys' => count(DataKeys::all()),
                'url' => admin_url('security_check'),
            ];
        } catch (\Throwable $e) {
            return ['configured' => false, 'unavailable' => true, 'keys' => 0, 'url' => admin_url('security_check')];
        }
    }
}
