<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\ApiClient;
use Exceedone\Exment\Model\ApiRateRaise;
use Exceedone\Exment\Services\ApiRateRaises;
use Exceedone\Exment\Services\UserTimezone;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;

/**
 * Temporary raises of the API rate limit (ISS00442): the list (the ones past their time
 * too) and a new one. Only system administrators (Auth\Permission, "api_rate_raises"),
 * only when the feature is used (Features, "api_rate_raise").
 */
class ApiRateRaiseController extends AdminControllerBase
{
    /** The users offered in the list of the form. */
    public const MAX_USERS = 2000;

    public function __construct()
    {
        $this->setPageInfo(exmtrans('api_rate_raise.header'), exmtrans('api_rate_raise.header'), exmtrans('api_rate_raise.description'), 'fa-tachometer');
    }

    /**
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        static::onlyAdministrators();
        $this->AdminContent($content);

        $clients = ApiClient::query()->where('revoked', false)->orderBy('name')->get()
            ->mapWithKeys(function ($client) {
                return [strval($client->id) => strval($client->name)];
            })->all();
        $users = getModelName(SystemTableName::USER)::query()->orderBy('id')->limit(static::MAX_USERS)->get()
            ->mapWithKeys(function ($user) {
                return [strval($user->id) => strval($user->getLabel())];
            })->all();

        $raises = ApiRateRaise::query()->orderByDesc('id')->get();
        $userIds = $raises->pluck('created_user_id')
            ->merge($raises->where('target_type', ApiRateRaise::TARGET_USER)->pluck('target_id'))
            ->filter()->map('intval')->unique()->diff(array_map('intval', array_keys($users)))->all();
        foreach (getModelName(SystemTableName::USER)::query()->whereKey($userIds)->get() as $user) {
            $users[strval($user->id)] = strval($user->getLabel());
        }

        $content->row(view('exment::api_rate_raise.index', [
            'raises' => $raises,
            'users' => $users,
            'clients' => $clients,
            'base' => ApiRateRaises::base(),
            'ceiling' => ApiRateRaises::ceiling(),
            'maxHours' => ApiRateRaises::maxHours(),
            'local' => function ($value): string {
                return strval(UserTimezone::display($value, 'Y-m-d H:i') ?? '');
            },
            'storeUrl' => admin_url('api_rate_raises'),
            'deleteUrl' => function (int $id): string {
                return admin_urls('api_rate_raises', $id, 'delete');
            },
            'old' => $request->old(),
        ]));
        return $content;
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        static::onlyAdministrators();
        $input = $request->only(['target_type', 'target_id', 'per_minute', 'hours', 'reason']);
        // the user or the client, from the select of its type
        $type = strval($input['target_type'] ?? '');
        $input['target_id'] = $type === ApiRateRaise::TARGET_CLIENT ? $request->get('client_id') : $request->get('user_id');
        $error = ApiRateRaises::validate($input);
        if (isset($error)) {
            admin_toastr($error, 'error');
            return redirect(admin_url('api_rate_raises'))->withInput();
        }
        ApiRateRaises::add($input, static::userId());
        admin_toastr(trans('admin.save_succeeded'));
        return redirect(admin_url('api_rate_raises'));
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     */
    public function delete(Request $request, string $id)
    {
        static::onlyAdministrators();
        $raise = ApiRateRaise::find($id);
        if (isset($raise)) {
            ApiRateRaises::delete($raise, static::userId());
            admin_toastr(trans('admin.delete_succeeded'));
        }
        return redirect(admin_url('api_rate_raises'));
    }

    protected static function userId(): ?int
    {
        $id = \Exment::getUserId();
        return is_null($id) ? null : intval($id);
    }

    /**
     * The system administrators only: also on a site where the roles are not
     * used (Auth\Permission lets everyone in then).
     */
    protected static function onlyAdministrators(): void
    {
        $user = \Exment::user();
        if (!isset($user) || !$user->isAdministrator()) {
            abort(403);
        }
    }
}
