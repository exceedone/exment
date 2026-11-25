<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Services\Trash;
use Exceedone\Exment\Services\UserTimezone;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;

/**
 * The trash (ISS00334): the deleted rows of all the tables the user may see the deleted rows
 * of, to restore them or delete them for good (Services\Trash). The feature "trash" (Features)
 * and the endpoint "trash" (Auth\Permission) keep it to the users who may see the deleted rows
 * of a table at least.
 */
class TrashController extends AdminControllerBase
{
    /** The users offered in the filter. */
    public const MAX_USERS = 1000;

    /** The filters kept in the URL. */
    public const FILTERS = ['table', 'user', 'from', 'to'];

    public function __construct()
    {
        $this->setPageInfo(exmtrans('trash.header'), exmtrans('trash.header'), exmtrans('trash.description'), 'fa-trash');
    }

    /**
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        static::check();
        $this->AdminContent($content);

        $tables = Trash::tables();
        $filters = static::filters($request);
        $perPage = intval($request->get('per_page', Trash::PER_PAGE));
        $page = intval($request->get('page', 1));
        $paginator = Trash::paginate($filters, $perPage, $page, $tables);
        $paginator->withPath(admin_url('trash'))->appends(array_filter($filters + ['per_page' => $request->get('per_page')], function ($v) {
            return !is_nullorempty($v);
        }));

        $users = getModelName(SystemTableName::USER)::query()->orderBy('id')->limit(static::MAX_USERS)->get()
            ->mapWithKeys(function ($user) {
                return [strval($user->id) => strval($user->getLabel())];
            })->all();

        $content->row(view('exment::trash.index', [
            'tables' => $tables->mapWithKeys(function ($table) {
                return [strval($table->id) => strval($table->table_view_name)];
            })->all(),
            'users' => $users,
            'filters' => $filters,
            'paginator' => $paginator,
            'local' => function ($value): string {
                return strval(UserTimezone::display($value, 'Y-m-d H:i') ?? '');
            },
            'restoreUrl' => admin_url('trash/restore'),
            'deleteUrl' => admin_url('trash/delete'),
            'returnQuery' => http_build_query(array_filter($filters + ['page' => $page > 1 ? $page : null], function ($v) {
                return !is_nullorempty($v);
            })),
        ]));
        return $content;
    }

    /**
     * Restore the rows chosen ("items[]" = "<table id>:<row id>").
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function restore(Request $request)
    {
        static::check();
        $items = Trash::parseItems($request->get('items'));
        $error = is_null($items) ? strval(exmtrans('trash.message.not_found')) : Trash::restore($items);
        return $this->back($request, $error, strval(exmtrans('custom_value.message.restore_succeeded')));
    }

    /**
     * Delete the rows chosen for good.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function delete(Request $request)
    {
        static::check();
        $items = Trash::parseItems($request->get('items'));
        $error = is_null($items) ? strval(exmtrans('trash.message.not_found')) : Trash::forceDelete($items);
        return $this->back($request, $error, strval(trans('admin.delete_succeeded')));
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function back(Request $request, ?string $error, string $success)
    {
        if (isset($error)) {
            admin_toastr($error, 'error');
        } else {
            admin_toastr($success);
        }
        parse_str(strval($request->get('return_query', '')), $query);
        $query = array_intersect_key($query, array_flip(array_merge(static::FILTERS, ['page'])));
        return redirect(admin_url('trash') . (count($query) > 0 ? '?' . http_build_query($query) : ''));
    }

    /**
     * @return array{table: string, user: string, from: string, to: string}
     */
    protected static function filters(Request $request): array
    {
        $filters = [];
        foreach (static::FILTERS as $key) {
            $value = $request->get($key);
            $filters[$key] = is_scalar($value) ? trim(strval($value)) : '';
        }
        /** @var array{table: string, user: string, from: string, to: string} $filters */
        return $filters;
    }

    /**
     * Only the users who may see the deleted rows of a table at least (also when the URL is
     * opened directly).
     */
    protected static function check(): void
    {
        if (!Trash::available()) {
            abort(403);
        }
    }
}
