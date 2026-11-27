<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Services\Mcp\McpGuide;
use Carbon\Carbon;
use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Providers\LoginUserProvider;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\UserApiKey;
use Exceedone\Exment\Services\DemoMode;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\UserApiKeys;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The API keys of the users (Services\UserApiKeys, ISS00437):
 * - auth/api_keys: the keys of the login user (make, see, stop); the users the feature allows
 * - api_keys: all the keys of the site (see, stop one, stop all of a user); the system administrators
 *
 * A new key is shown once, in the answer to the form, and is kept nowhere (not in the session).
 */
class UserApiKeyController extends AdminControllerBase
{
    /** The end dates offered on the form (days). */
    public const DAYS = [7, 30, 90, 180, 365];

    /** The system tables shown with the others in the list of tables of a key. */
    protected const SHOWN_SYSTEM_TABLES = [SystemTableName::USER, SystemTableName::ORGANIZATION];

    /** The scopes by what they are about, in the order of the page. */
    protected const SCOPE_GROUPS = [
        'data' => [ApiScope::TABLE_READ, ApiScope::VIEW_READ, ApiScope::VALUE_READ, ApiScope::VALUE_WRITE],
        'workflow' => [ApiScope::WORKFLOW_READ, ApiScope::WORKFLOW_EXECUTE],
        'notify' => [ApiScope::NOTIFY_READ, ApiScope::NOTIFY_WRITE],
        'user_org' => [ApiScope::ME, ApiScope::USER_ORG_READ, ApiScope::USER_ORG_WRITE, ApiScope::ROLE_GROUP_READ, ApiScope::ROLE_GROUP_WRITE],
        'plugin' => [ApiScope::PLUGIN],
        'log' => [ApiScope::LOG],
    ];

    /** The scopes of "from another system by the API": read, and write the data and the workflow. */
    protected const API_SCOPES = [
        ApiScope::ME, ApiScope::TABLE_READ, ApiScope::VIEW_READ, ApiScope::VALUE_READ, ApiScope::VALUE_WRITE,
        ApiScope::WORKFLOW_READ, ApiScope::WORKFLOW_EXECUTE, ApiScope::NOTIFY_READ,
    ];

    /** Tries of the password when making keys, in PASSWORD_DECAY_SECONDS. */
    public const PASSWORD_TRIES = 5;
    public const PASSWORD_DECAY_SECONDS = 15 * 60;

    public function __construct()
    {
        $this->setPageInfo(exmtrans('api_key.page_header'), exmtrans('api_key.page_header'), exmtrans('api_key.description'), 'fa-key');
    }

    /**
     * The keys of the login user, and the form to make one.
     *
     * @param array<int, string> $newScopes the scopes of the key just made (the settings of MCP carry it when they fit)
     * @return Content
     */
    public function index(Request $request, Content $content, ?string $newKey = null, ?string $error = null, ?string $errorLink = null, array $newScopes = [])
    {
        $this->abortUnlessAllowed();
        $user = \Exment::user();

        $tables = CustomTable::filterList(null, ['permissions' => Permission::AVAILABLE_VIEW_CUSTOM_VALUE])
            ->map(function ($table) {
                return [
                    'name' => strval($table->table_name),
                    'label' => strval($table->table_view_name),
                    // the tables of Exment itself (mail templates, mail logs...): hidden until asked; not the users and organizations
                    'system' => boolval($table->system_flg) && !in_array(strval($table->table_name), static::SHOWN_SYSTEM_TABLES, true),
                ];
            })->values()->all();

        // the purposes preset the scopes; the boxes show what was sent, or the preset of the purpose
        $purposes = static::purposes();
        $purpose = static::text($request, 'purpose');
        if (!array_key_exists($purpose, $purposes)) {
            $purpose = McpGuide::allowed() ? 'mcp' : 'read_only';
        }
        $checkedScopes = $request->has('scopes') ? static::texts($request, 'scopes') : $purposes[$purpose]['scopes'];

        $this->AdminContent($content);
        $content->row(view('exment::api_key.mine', [
            'keys' => $this->rows(UserApiKey::where('user_id', $user->base_user_id)->orderByDesc('id')->get()),
            'new_key' => $newKey,
            'error' => $error,
            'error_link' => $errorLink,
            'sso' => UserApiKeys::isSsoLogin($user),
            // what was sent, as text only (never the password)
            'old' => [
                'name' => static::text($request, 'name'),
                'scopes' => static::texts($request, 'scopes'),
                'table_type' => static::text($request, 'table_type'),
                'tables' => static::texts($request, 'tables'),
                'allowed_ips' => static::text($request, 'allowed_ips'),
                'days' => static::text($request, 'days'),
                'client_cert_fingerprint' => static::text($request, 'client_cert_fingerprint'),
            ],
            'purposes' => $purposes,
            'purpose' => $purpose,
            'scope_groups' => static::scopeGroups(),
            'checked_scopes' => $checkedScopes,
            'tables' => $tables,
            'days' => static::days(),
            'default_days' => min(UserApiKeys::DEFAULT_DAYS, static::maxDaysOfLogin()),
            'max_keys' => UserApiKeys::maxActiveKeys(),
            'ask_password' => $this->asksPassword(),
            'action' => admin_url('auth/api_keys'),
            'read_only' => DemoMode::enabled(),
            // the MCP server (ISS02879): a key for it, and the settings of Claude with the new key
            'mcp' => [
                'allowed' => McpGuide::allowed(),
                'url' => McpGuide::url(),
                'scopes' => McpGuide::scopes(),
                'configs' => McpGuide::configs(isset($newKey) && McpGuide::covers($newScopes) ? $newKey : null),
                'placeholder_configs' => McpGuide::configs(),
                'for_new_key' => isset($newKey) && McpGuide::covers($newScopes),
            ],
        ]));
        return $content;
    }

    /**
     * Make a key of the login user: the answer shows it, once.
     *
     * @return mixed
     */
    public function store(Request $request, Content $content)
    {
        $this->abortUnlessAllowed();
        if (DemoMode::enabled()) {
            admin_toastr(DemoMode::message(), 'error');
            return redirect(admin_url('auth/api_keys'));
        }
        $user = \Exment::user();

        // a user who signs in with a password says it again (a key acts as the user). The check does
        // not count toward the lockout of the login page, so it has its own limit of tries.
        if ($this->asksPassword()) {
            $limiter = static::passwordLimiterKey((int)$user->id);
            if (RateLimiter::tooManyAttempts($limiter, static::PASSWORD_TRIES)) {
                return $this->index($request, $content, null, exmtrans('api_key.errors.password_tries', ceil(RateLimiter::availableIn($limiter) / 60)));
            }
            if (!LoginUserProvider::ValidateCredential($user, ['password' => static::text($request, 'current_password')])) {
                RateLimiter::hit($limiter, static::PASSWORD_DECAY_SECONDS);
                return $this->index($request, $content, null, exmtrans('validation.current_password'));
            }
            RateLimiter::clear($limiter);
        } elseif (UserApiKeys::isSsoLogin($user) && !UserApiKeys::signedInRecently()) {
            // a user of SSO signs in again first (the IdP says the user is still there)
            return $this->index($request, $content, null, exmtrans('api_key.errors.sign_in_again', 15), admin_url('auth/logout'));
        }

        $scopeType = static::text($request, 'scope_type');
        $scopes = $scopeType === 'custom'
            ? static::texts($request, 'scopes')
            // a key for MCP: what its tools read, and nothing more (ISS02879)
            : ($scopeType === 'mcp' ? McpGuide::scopes() : UserApiKeys::readOnlyScopes());
        $tables = static::text($request, 'table_type') === 'some'
            ? static::texts($request, 'tables')
            : null;
        // only the tables the user may see (a name sent by hand is not taken)
        if (isset($tables)) {
            $mine = CustomTable::filterList(null, ['permissions' => Permission::AVAILABLE_VIEW_CUSTOM_VALUE])->pluck('table_name')->map('strval')->all();
            if (count(array_diff($tables, $mine)) > 0) {
                return $this->index($request, $content, null, exmtrans('api_key.errors.tables'));
            }
        }
        $days = intval(static::text($request, 'days'));
        if (!in_array($days, static::days(), true)) {
            $days = min(UserApiKeys::DEFAULT_DAYS, static::maxDaysOfLogin());
        }

        try {
            [$key, $plain] = UserApiKeys::create(
                (int)$user->base_user_id,
                static::text($request, 'name'),
                $scopes,
                $tables,
                static::text($request, 'allowed_ips'),
                Carbon::now()->addDays($days),
                static::text($request, 'client_cert_fingerprint'),
                (int)$user->base_user_id,
                // the login that makes it (any provider, as SSO)
                (int)$user->id
            );
        } catch (\InvalidArgumentException $e) {
            return $this->index($request, $content, null, exmtrans('api_key.errors.' . $e->getMessage()));
        }

        // the user is told by mail (not the key: its name, end date and the address of the request)
        UserApiKeys::mailCreated($key, $request->ip());

        // an empty form: the values of the key are not kept in the fields
        return $this->index(new Request(), $content, $plain, null, null, (array)$key->scopes);
    }

    /**
     * Stop a key of the login user.
     *
     * @return mixed
     */
    public function revoke(Request $request, string $id)
    {
        $this->abortUnlessAllowed();
        $key = UserApiKey::where('id', $id)->where('user_id', \Exment::user()->base_user_id)->first();
        if (!isset($key)) {
            abort(404);
        }
        UserApiKeys::revoke($key, (int)\Exment::user()->base_user_id);
        admin_toastr(exmtrans('api_key.message_revoked'));
        return redirect(admin_url('auth/api_keys'));
    }

    /**
     * All the keys of the site (the system administrators: Auth\Permission "api_keys").
     *
     * @return Content
     */
    public function adminIndex(Request $request, Content $content)
    {
        $this->abortUnlessAdministrator();
        // the active keys by default; all, or the stopped and expired ones, when asked
        $status = static::text($request, 'status');
        if (!in_array($status, ['active', 'inactive', 'all'], true)) {
            $status = 'active';
        }
        $query = UserApiKey::query()->orderByDesc('id');
        if ($status === 'active') {
            $query->whereNull('revoked_at')->where('expires_at', '>', Carbon::now());
        } elseif ($status === 'inactive') {
            $query->where(function ($q) {
                $q->whereNotNull('revoked_at')->orWhere('expires_at', '<=', Carbon::now());
            });
        }
        $paginator = $query->paginate(50)->appends(['status' => $status]);

        $userIds = collect($paginator->items())->pluck('user_id')->unique()->all();
        $users = getModelName(SystemTableName::USER)::query()->whereIn('id', $userIds)->get()->mapWithKeys(function ($user) {
            return [intval($user->id) => strval($user->getValue('user_name'))];
        })->all();

        $this->AdminContent($content);
        $content->row(view('exment::api_key.admin', [
            'keys' => $this->rows($paginator->items(), $users),
            'paginator' => $paginator,
            'status' => $status,
            'action' => admin_url('api_keys'),
            'feature_url' => admin_url('feature'),
            'enabled' => Features::enabled(Features::API_KEYS),
            'read_only' => DemoMode::enabled(),
        ]));
        return $content;
    }

    /**
     * Stop one key (any user's).
     *
     * @return mixed
     */
    public function adminRevoke(Request $request, string $id)
    {
        $this->abortUnlessAdministrator();
        $key = UserApiKey::find($id);
        if (!isset($key)) {
            abort(404);
        }
        $wasActive = $key->isActive();
        UserApiKeys::revoke($key, (int)\Exment::user()->base_user_id);
        if ($wasActive) {
            UserApiKeys::mailRevokedByAdmin($key->user_id, 1);
        }
        admin_toastr(exmtrans('api_key.message_revoked'));
        return redirect(admin_url('api_keys'));
    }

    /**
     * Stop all the keys of a user.
     *
     * @return mixed
     */
    public function adminRevokeUser(Request $request, string $userId)
    {
        $this->abortUnlessAdministrator();
        $count =UserApiKeys::revokeAllOfUser(intval($userId), (int)\Exment::user()->base_user_id);
        UserApiKeys::mailRevokedByAdmin(intval($userId), $count);
        admin_toastr(exmtrans('api_key.message_revoked_user', $count));
        return redirect(admin_url('api_keys'));
    }

    /**
     * A field of the form as text ('' when it is not text, as name[]=x).
     */
    protected static function text(Request $request, string $name): string
    {
        $value = $request->input($name);
        return is_string($value) || is_numeric($value) ? strval($value) : '';
    }

    /**
     * A field of the form as a list of texts (what is not text is left out).
     *
     * @return array<int, string>
     */
    protected static function texts(Request $request, string $name): array
    {
        $value = $request->input($name);
        return array_values(array_map('strval', array_filter(is_array($value) ? $value : [], 'is_string')));
    }

    /**
     * The end dates offered (days), within the most days of the site.
     *
     * @return array<int, int>
     */
    protected static function days(): array
    {
        $max = static::maxDaysOfLogin();
        $days = array_values(array_filter(static::DAYS, function ($d) use ($max) {
            return $d <= $max;
        }));
        return count($days) > 0 ? $days : [$max];
    }

    /**
     * The most days of a key of the login user (shorter for a login of SSO).
     */
    protected static function maxDaysOfLogin(): int
    {
        $user = \Exment::user();
        return UserApiKeys::maxDays(isset($user) && UserApiKeys::isSsoLogin($user));
    }

    /**
     * The name of the tries of the password of a login user, of this site.
     */
    public static function passwordLimiterKey(int $loginUserId): string
    {
        $site = \Exceedone\Exment\Services\PluginDistribution\SiteId::get()
            ?? strval(config('database.connections.' . config('database.default') . '.database'));
        return 'exment-apikey-password:' . substr(hash('sha256', $site), 0, 16) . ':' . $loginUserId;
    }

    /**
     * Whether the login user signs in with a password (then it is asked when making a key).
     */
    protected function asksPassword(): bool
    {
        $user = \Exment::user();
        // (the login of SSO has a provider: it signs in again instead, see store())
        return isset($user) && $user->login_type == LoginType::PURE && !UserApiKeys::isSsoLogin($user) && !DemoMode::enabled();
    }

    /**
     * The keys of everyone: the system administrators only, also when the site does not use the
     * role groups (then Exment lets every user into the admin pages; not into these).
     */
    protected function abortUnlessAdministrator(): void
    {
        $user = \Exment::user();
        if (!isset($user) || !$user->isAdministrator()) {
            abort(403);
        }
    }

    protected function abortUnlessAllowed(): void
    {
        // the feature guard answers 404 when the feature is off; here: the user is not in its role groups
        if (!Features::allowed(Features::API_KEYS)) {
            abort(403);
        }
    }

    /**
     * The keys as rows of the pages (never the hash).
     *
     * @param iterable<UserApiKey> $keys
     * @param array<int, string> $users
     * @return array<int, array<string, mixed>>
     */
    protected function rows(iterable $keys, array $users = []): array
    {
        $rows = [];
        foreach ($keys as $key) {
            $status = isset($key->revoked_at) ? 'revoked' : ($key->expires_at->isFuture() ? 'active' : 'expired');
            // the uses not written yet are in the cache (UserApiKeys::touch)
            $usage = UserApiKeys::usageOf($key);
            $refusedShown = isset($key->last_refused_at) && (!isset($usage['last_used_at']) || $key->last_refused_at->greaterThan($usage['last_used_at']));
            $rows[] = [
                'id' => $key->id,
                'user_id' => $key->user_id,
                'user_name' => $users[$key->user_id] ?? strval($key->user_id),
                'name' => $key->name,
                'prefix' => $key->key_prefix,
                'scopes' => implode(', ', (array)$key->scopes),
                'tables' => isset($key->tables) ? implode(', ', (array)$key->tables) : null,
                'ips' => implode(', ', $key->ipList()),
                'cert' => isset($key->client_cert_fingerprint),
                'expires_at' => $key->expires_at->format('Y-m-d H:i'),
                'last_used_at' => $usage['last_used_at']?->format('Y-m-d H:i'),
                'last_used_ip' => $usage['last_used_ip'],
                'use_count' => $usage['use_count'],
                'created_at' => $key->created_at?->format('Y-m-d H:i'),
                'status' => $status,
                // the last refusal and why (the log is not for every administrator), when it came
                // after the last use: a refusal the key got past is not shown any more
                'last_refused_at' => $refusedShown && isset($key->last_refused_at) ? $key->last_refused_at->format('Y-m-d H:i') : null,
                'last_refused_reason' => $refusedShown && isset($key->last_refused_reason) ? exmtrans('api_key.refusals.' . $key->last_refused_reason) : null,
            ];
        }
        return $rows;
    }

    /**
     * What a key is for: each presets its scopes (the user may change them).
     *
     * @return array<string, array{icon: string, scopes: array<int, string>}>
     */
    protected static function purposes(): array
    {
        return [
            'mcp' => ['icon' => 'fa-robot', 'scopes' => McpGuide::scopes()],
            'api' => ['icon' => 'fa-plug', 'scopes' => static::API_SCOPES],
            'read_only' => ['icon' => 'fa-eye', 'scopes' => UserApiKeys::readOnlyScopes()],
        ];
    }

    /**
     * The scopes of the page by group, only those the site has (a scope added later goes to "others").
     *
     * @return array<string, array<int, string>>
     */
    protected static function scopeGroups(): array
    {
        $available = UserApiKeys::availableScopes();
        $groups = [];
        foreach (static::SCOPE_GROUPS as $group => $scopes) {
            $groups[$group] = array_values(array_intersect($scopes, $available));
        }
        $rest = array_values(array_diff($available, array_merge(...array_values(static::SCOPE_GROUPS))));
        if (count($rest) > 0) {
            $groups['others'] = $rest;
        }
        return array_filter($groups, function ($scopes) {
            return count($scopes) > 0;
        });
    }

}
