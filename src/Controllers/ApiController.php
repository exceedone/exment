<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\OperationLog;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ErrorCode;
use Validator;
use Carbon\Carbon;

/**
 * Api about target table
 */
class ApiController extends AdminControllerBase
{
    use ApiTrait;

    /**
     * get Exment version
     */
    public function version(Request $request)
    {
        return response()->json(['version' => (new \Exceedone\Exment\Exment())->version(false)]);
    }

    /**
     * get login user info
     * @param Request $request
     * @return array|null
     */
    public function me(Request $request)
    {
        $base_user = \Exment::user()->base_user ?? null;
        if (!isset($base_user)) {
            return null;
        }
        $base_user = $base_user->makeHidden(CustomTable::getEloquent(SystemTableName::USER)->getMakeHiddenArray())
            ->toArray();

        if ($request->has('dot') && boolval($request->get('dot'))) {
            $base_user = array_dot($base_user);
        }
        return $base_user;
    }

    /**
     * get login user avatar
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse|null
     */
    public function avatar(Request $request)
    {
        $avatar = \Exment::user()->avatar ?? null;
        $isBase64 = $request->has('base64') && boolval($request->get('base64'));
        $isdefault = $request->has('default') && boolval($request->get('default'));

        // Is not has avatar
        if (!isset($avatar)) {
            // If download default instead of avatar
            if ($isdefault) {
                $defaultPath = base_path(path_join('public', Define::USER_IMAGE_LINK));
                if ($isBase64) {
                    return response()->json(['base64' => base64_encode(\File::get($defaultPath))]);
                }
                return response()->stream(function () use ($defaultPath) {
                    echo \File::get($defaultPath);
                }, 200, ['Content-Type' => 'image/png']);
            }

            // not download default, return as null
            if ($isBase64) {
                return response()->json(['base64' => null]);
            }
            return null;
        }

        if ($isBase64) {
            return response()->json(['base64' => base64_encode(\Storage::disk(config('admin.upload.disk'))->get($avatar))]);
        }
        return \Storage::disk(config('admin.upload.disk'))->response($avatar);
    }

    /**
     * get table list
     * @return mixed
     */
    public function tablelist(Request $request)
    {
        // if (!\Exment::user()->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
        //     return abortJson(403, ErrorCode::PERMISSION_DENY());
        // }

        // get and check query parameter
        if (($count = $this->getCount($request)) instanceof Response) {
            return $count;
        }

        $options = [
            'getModel' => false,
            'with' => $this->getJoinTables($request, 'custom'),
            'permissions' => Permission::AVAILABLE_ACCESS_CUSTOM_VALUE
        ];
        // filterd by id
        if ($request->has('id')) {
            $ids = explode(',', $request->get('id'));
            $options['filter'] = function ($model) use ($ids) {
                $model->whereIn('id', $ids);
                return $model;
            };
        }

        // filter table
        $query = CustomTable::query();
        CustomTable::filterList($query, $options);
        return $query->paginate($count ?? config('exment.api_default_data_count'));
    }

    /**
     * get column list
     * @return mixed
     */
    public function columns(Request $request)
    {
        return $this->_getcolumns($request, false);
    }

    /**
     * get column list
     * @return mixed
     */
    public function indexcolumns(Request $request)
    {
        return $this->_getcolumns($request);
    }

    /**
     * get column list
     * @return mixed
     */
    protected function _getcolumns(Request $request, $onlyIndex = true)
    {
        if (!\Exment::user()->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
        }

        // if execute as selecting column_type
        if ($request->has('custom_type')) {
            // check user or organization
            if (!ColumnType::isUserOrganization($request->get('q'))) {
                return [];
            }
        }

        $table = $request->get('q');
        if (!isset($table)) {
            return [];
        }

        if ($onlyIndex) {
            return CustomTable::getEloquent($table)->custom_columns()->indexEnabled()->get();
        } else {
            return CustomTable::getEloquent($table)->custom_columns()->get();
        }
    }

    /**
     * get filter view list
     * @return mixed
     */
    public function filterviews(Request $request)
    {
        if (!\Exment::user()->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
        }

        $table = $request->get('q');
        if (!isset($table)) {
            return [];
        }

        // if execute as selecting column_type
        if ($request->has('custom_type')) {
            // check user or organization
            if (!ColumnType::isUserOrganization($table)) {
                return [];
            }
        }
        $table = CustomTable::getEloquent($table);
        if (!isset($table)) {
            return [];
        }

        return CustomView::where('custom_table_id', $table->id)
            ->where('view_kind_type', ViewKindType::FILTER)
            ->get();
    }

    /**
     * get table data by id or table_name
     * @param mixed $tableKey id or table_name
     * @return mixed
     */
    public function table($tableKey, Request $request)
    {
        $withs = $this->getJoinTables($request, 'custom');
        $table = CustomTable::getEloquent($tableKey, $withs);

        if (!isset($table)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }

        if (!$table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
        }
        return $table;
    }

    /**
     * get column data by id
     * @param mixed $id
     * @return mixed
     */
    public function column($id, Request $request)
    {
        return $this->responseColumn($request, CustomColumn::find($id));
    }

    /**
     * get view
     * @param mixed $idOrSuuid if length is 20, use suuid
     * @return mixed
     */
    public function view(Request $request, $idOrSuuid)
    {
        $query = CustomView::query();
        if (strlen($idOrSuuid) == 20) {
            $query->where('suuid', $idOrSuuid);
        } else {
            $query->where('id', $idOrSuuid);
        }

        return $query->first();
    }



    /**
     * get columns that belongs table using column id
     * 1. find column and get column info
     * 2. get column target table
     * 3. get columns that belongs to target table
     * @param mixed $id select_table custon_column id
     */
    public function targetBelongsColumns($id)
    {
        if (!isset($id)) {
            return [];
        }
        // get custom column
        $custom_column = CustomColumn::getEloquent($id);

        // if column_type is not select_table, return []
        if (!ColumnType::isSelectTable(array_get($custom_column, 'column_type'))) {
            return [];
        }

        // get select_target_table
        $select_target_table = $custom_column->select_target_table;
        if (!isset($select_target_table)) {
            return [];
        }
        return CustomTable::getEloquent($select_target_table)
            ->custom_columns()
            ->selectRaw('id as view_id, column_view_name as view_name')
            ->get();
    }



    /**
     * get auth logs
     */
    public function authLogs(Request $request)
    {
        $login_user = \Exment::user();
        if (!$login_user->hasPermission(Permission::SYSTEM)) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
        }

        // get and check query parameter
        if (($count = $this->getCount($request)) instanceof Response) {
            return $count;
        }

        // get query
        $query = OperationLog::query()
            ->with('user');

        // filterd by items
        if ($request->has('login_user_id')) {
            $query->where('user_id', $request->get('login_user_id'));
        }
        if ($request->has('base_user_id')) {
            $base_user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel($request->get('base_user_id'));
            if ($base_user) {
                $query->whereIn('user_id', $base_user->login_users->pluck('id')->toArray());
            } else {
                $query->whereNotMatch();
            }
        }
        if ($request->has('path')) {
            $query->where('path', $request->get('path'));
        }
        if ($request->has('method')) {
            $query->where('method', strtoupper($request->get('method')));
        }
        if ($request->has('ip')) {
            $query->where('ip', $request->get('ip'));
        }
        if ($request->has('target_datetime_start')) {
            $query->where('created_at', '>=', $request->get('target_datetime_start'));
        }
        if ($request->has('target_datetime_end')) {
            // Append 1 second for sql server
            $target_datetime_end = Carbon::parse($request->get('target_datetime_end'));
            $target_datetime_end = $target_datetime_end->addSeconds(1);
            $query->where('created_at', '<', $target_datetime_end->format('Y-m-d H:i:s'));
        }

        $query->orderBy('created_at', 'desc');
        $paginator = $query->paginate($count);

        /** @phpstan-ignore-next-line need Class Reflection Extension */
        $paginator->appends($request->all([
            'login_user_id',
            'base_user_id',
            'path',
            'method',
            'ip',
            'target_datetime_start',
            'target_datetime_end',
        ]))->makeHidden('user');

        return $paginator;
    }


    /**
     * get auth log
     */
    public function authLog(Request $request, $id)
    {
        $login_user = \Exment::user();
        if (!$login_user->hasPermission(Permission::SYSTEM)) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
        }

        // get and check query parameter
        if (($count = $this->getCount($request)) instanceof Response) {
            return $count;
        }

        $result = OperationLog::find($id);
        return $result ? $result->makeHidden('user') : [];
    }



    /**
     * create notify
     */
    public function notifyCreate(Request $request)
    {
        $is_single = false;

        $validator = Validator::make($request->all(), [
            'target_users' => 'required',
            'notify_subject' => 'required',
            'notify_body' => 'required',
        ]);
        if ($validator->fails()) {
            return abortJson(400, [
                'errors' => $this->getErrorMessages($validator)
            ], ErrorCode::VALIDATION_ERROR());
        }

        $target_users = $request->get('target_users');

        if (!is_array($target_users)) {
            $target_users = explode(',', $target_users);
            $is_single = count($target_users) == 1;
        }

        $error_users = collect($target_users)->filter(function ($target_user) {
            return is_null(getModelName(SystemTableName::USER)::find($target_user));
        });

        if ($error_users->count() > 0) {
            return abortJson(400, [
                'errors' => ['target_users' => exmtrans('api.errors.user_notfound', $error_users->implode(','))]
            ], ErrorCode::VALIDATION_ERROR());
        }

        $response = [];

        foreach ($target_users as $target_user) {
            $notify = new NotifyNavbar();

            $notify->fill([
                'notify_id' => 0,
                'target_user_id' => $target_user,
                'notify_subject' => $request->get('notify_subject'),
                'notify_body' => $request->get('notify_body'),
                'trigger_user_id' => \Exment::getUserId()
            ]);

            $notify->saveOrFail();

            $response[] = $notify;
        }

        if ($is_single && count($response) > 0) {
            return $response[0];
        } else {
            return $response;
        }
    }

    /**
     * Get notify List
     *
     * @param Request $request
     * @return \Illuminate\Config\Repository|\Illuminate\Contracts\Foundation\Application|mixed|Response|null
     */
    public function notifyList(Request $request)
    {
        if (($reqCount = $this->getCount($request)) instanceof Response) {
            return $reqCount;
        }

        // get notify NotifyNavbar list
        $query = NotifyNavbar::where('target_user_id', \Exment::getUserId());

        if (!boolval($request->get('all', false))) {
            $query->where('read_flg', false);
        }

        $count = $query->count();
        $paginator = $query->paginate($reqCount);

        // set appends
        $paginator->appends([
            'count' => $count,
        ]);
        if ($request->has('all')) {
            $paginator->appends([
                'all' => $request->get('all'),
            ]);
        }

        return $paginator;
    }

    /**
     * Get notify for page
     *
     * @param Request $request
     * @return array
     */
    public function notifyPage(Request $request)
    {
        // get notify NotifyNavbar list
        $query = NotifyNavbar::where('target_user_id', \Exment::getUserId())
            ->where('read_flg', false);

        $count = $query->count();
        $list = $query->take(5)->get();

        return [
            'count' => $count,
            'items' => $list->map(function ($l) {
                $custom_table = CustomTable::getEloquent(array_get($l, 'parent_type'));
                if (isset($custom_table)) {
                    $icon = $custom_table->getOption('icon');
                    $color = $custom_table->getOption('color');
                    $table_view_name = $custom_table->table_view_name;
                }

                return [
                    'id' => array_get($l, 'id'),
                    'icon' => $icon ?? 'fa-bell',
                    'color' => $color ?? null,
                    'table_view_name' => $table_view_name ?? null,
                    'label' => array_get($l, 'notify_subject'),
                    'href' => admin_urls('notify_navbar', $l->id)
                ];
            }),
            'noItemMessage' => exmtrans('notify_navbar.message.no_newitem')
        ];
    }

    /**
     * Get user or organization for select
     *
     * @param Request $request
     * @return \Illuminate\Config\Repository|\Illuminate\Contracts\Foundation\Application|LengthAwarePaginator|mixed|Response|null
     */
    public function userOrganizationSelect(Request $request)
    {
        $keys = [SystemTableName::USER];
        if (System::organization_available()) {
            $keys[] = SystemTableName::ORGANIZATION;
        }

        $results = collect();
        // default count
        $count = config('exment.api_default_data_count', 20);
        foreach ($keys as $key) {
            $custom_table = CustomTable::getEloquent($key);

            if (($code = $custom_table->enableAccess()) !== true) {
                return abortJson(403, ErrorCode::PERMISSION_DENY());
            }

            $validator = \Validator::make($request->all(), [
                'q' => 'required',
            ]);
            if ($validator->fails()) {
                return abortJson(400, [
                    'errors' => $this->getErrorMessages($validator)
                ], ErrorCode::VALIDATION_ERROR());
            }

            // filtered query
            $q = $request->get('q');

            if (($count = $this->getCount($request)) instanceof Response) {
                return $count;
            }

            $options = [
                'makeHidden' => true,
                'maxCount' => $count,
            ];
            if (!is_null($display_table_id = $request->get('display_table_id'))) {
                $options['display_table'] = $display_table_id;
            }

            $result = $custom_table->searchValue($q, $options);

            // if call as select ajax, return id and text array
            $results = $results->merge(
                $result->map(function ($value) use ($key) {
                    return [
                        'id' => $key . '_' . $value->id,
                        'text' => $value->label,
                    ];
                })
            );
        }

        // get as paginator
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator($results, count($results), $count, 1);

        return $paginator;
    }
}
