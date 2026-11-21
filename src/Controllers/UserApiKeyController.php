<?php

namespace Exceedone\Exment\Controllers;

use Carbon\Carbon;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\UserApiKey;
use Exceedone\Exment\Services\DemoMode;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\UserApiKeys;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;

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
    public const DAYS = [30, 90, 180, 365];

    public function __construct()
    {
        $this->setPageInfo(exmtrans('api_key.header'), exmtrans('api_key.header'), exmtrans('api_key.description'), 'fa-key');
    }

    /**
     * The keys of the login user, and the form to make one.
     *
     * @return Content
     */
    public function index(Request $request, Content $content, ?string $newKey = null, ?string $error = null)
    {
        $this->abortUnlessAllowed();
        $user = \Exment::user();

        $tables = CustomTable::filterList(null, ['permissions' => Permission::AVAILABLE_VIEW_CUSTOM_VALUE])
            ->map(function ($table) {
                return ['name' => strval($table->table_name), 'label' => strval($table->table_view_name)];
            })->values()->all();

        $this->AdminContent($content);
        $content->row(view('exment::api_key.mine', [
            'keys' => $this->rows(UserApiKey::where('user_id', $user->base_user_id)->orderByDesc('id')->get()),
            'new_key' => $newKey,
            'error' => $error,
            'old' => $request->all(),
            'scopes' => UserApiKeys::availableScopes(),
            'read_only_scopes' => UserApiKeys::readOnlyScopes(),
            'tables' => $tables,
            'days' => static::DAYS,
            'default_days' => UserApiKeys::DEFAULT_DAYS,
            'action' => admin_url('auth/api_keys'),
            'read_only' => DemoMode::enabled(),
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

        $scopes = strval($request->input('scope_type')) === 'custom'
            ? array_map('strval', (array)$request->input('scopes', []))
            : UserApiKeys::readOnlyScopes();
        $tables = strval($request->input('table_type')) === 'some'
            ? array_map('strval', (array)$request->input('tables', []))
            : null;
        // only the tables the user may see (a name sent by hand is not taken)
        if (isset($tables)) {
            $mine = CustomTable::filterList(null, ['permissions' => Permission::AVAILABLE_VIEW_CUSTOM_VALUE])->pluck('table_name')->map('strval')->all();
            if (count(array_diff($tables, $mine)) > 0) {
                return $this->index($request, $content, null, exmtrans('api_key.errors.tables'));
            }
        }
        $days = intval($request->input('days', UserApiKeys::DEFAULT_DAYS));
        if (!in_array($days, static::DAYS, true)) {
            $days = UserApiKeys::DEFAULT_DAYS;
        }

        try {
            [, $plain] = UserApiKeys::create(
                (int)$user->base_user_id,
                strval($request->input('name')),
                $scopes,
                $tables,
                strval($request->input('allowed_ips')),
                Carbon::now()->addDays($days),
                strval($request->input('client_cert_fingerprint')),
                (int)$user->base_user_id
            );
        } catch (\InvalidArgumentException $e) {
            return $this->index($request, $content, null, exmtrans('api_key.errors.' . $e->getMessage()));
        }

        // an empty form: the values of the key are not kept in the fields
        return $this->index(new Request(), $content, $plain);
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
        $users = getModelName(SystemTableName::USER)::query()->get()->mapWithKeys(function ($user) {
            return [intval($user->id) => strval($user->getValue('user_name'))];
        })->all();

        $this->AdminContent($content);
        $content->row(view('exment::api_key.admin', [
            'keys' => $this->rows(UserApiKey::query()->orderByDesc('id')->get(), $users),
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
        $key = UserApiKey::find($id);
        if (!isset($key)) {
            abort(404);
        }
        UserApiKeys::revoke($key, (int)\Exment::user()->base_user_id);
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
        $count = UserApiKeys::revokeAllOfUser(intval($userId), (int)\Exment::user()->base_user_id);
        admin_toastr(exmtrans('api_key.message_revoked_user', $count));
        return redirect(admin_url('api_keys'));
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
                'last_used_at' => $key->last_used_at?->format('Y-m-d H:i'),
                'last_used_ip' => $key->last_used_ip,
                'use_count' => $key->use_count,
                'created_at' => $key->created_at?->format('Y-m-d H:i'),
                'status' => $status,
            ];
        }
        return $rows;
    }
}
