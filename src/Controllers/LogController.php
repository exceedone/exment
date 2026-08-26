<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\OperationLog;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\DataImportExport;
use ExmentAdminCore\Admin\Grid;
use ExmentAdminCore\Admin\Layout\Content;
use ExmentAdminCore\Admin\Show;
use ExmentAdminCore\Admin\Widgets\Box;
use ExmentAdminCore\Admin\Widgets\Form as WidgetForm;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Carbon\Carbon;
use Validator;

class LogController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct()
    {
        $this->setPageInfo(trans('admin.operation_log'), trans('admin.operation_log'), exmtrans('operation_log.description'), 'fa-file-text');
    }

    /**
     * Index interface with auto-delete settings box.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);
        $content->body($this->grid());
        // reading the log and administering it are two different jobs: an auditor
        // holding only "operation_log" reads the rows, but the retention settings
        // stay with the system role
        if (static::canManageLog()) {
            $content->row($this->settingFormBox());
        }
        return $content;
    }

    /**
     * Whether the current user may change retention settings or delete log rows.
     *
     * Reading is enough for PermissionEnum::OPERATION_LOG; changing what is kept
     * would let a reader erase their own trail, so it stays on the system role.
     *
     * @return bool
     */
    protected static function canManageLog(): bool
    {
        $user = \Exment::user();
        return $user ? $user->hasSystemPermission() : false;
    }

    /**
     * Build the auto-delete settings form box.
     *
     * @return Box
     */
    protected function settingFormBox()
    {
        // Merge old input (flashed by back()->withInput() on validation error)
        // so the form retains the user's submitted values instead of resetting to saved values.
        $formData = System::get_system_values();
        // Normalize null schedule fields to '' so selects show the placeholder instead of auto-selecting 0
        foreach (['operation_log_automatic_week', 'operation_log_automatic_month', 'operation_log_automatic_day', 'operation_log_automatic_hour', 'operation_log_automatic_minute'] as $f) {
            if (!array_key_exists($f, $formData) || is_null($formData[$f])) {
                $formData[$f] = '';
            }
        }
        $oldInput = session()->getOldInput();
        if (!empty($oldInput)) {
            $formData = array_merge($formData, $oldInput);
        }

        $form = new WidgetForm($formData);
        $form->action(admin_urls('auth/logs/setting'));
        $form->disableReset();

        $form->switchbool('operation_log_enable_automatic', exmtrans('operation_log.enable_automatic'))
            ->attribute(['data-filtertrigger' => true]);

        $form->number('operation_log_keep_days', exmtrans('operation_log.keep_days'))
            ->help(exmtrans('operation_log.keep_days_help'))
            ->min(1)
            ->attribute([
                'data-filter' => json_encode(['key' => 'operation_log_enable_automatic', 'value' => '1']),
                'required'    => true,
                'onkeydown'   => 'if(event.ctrlKey||event.metaKey)return; var nav=["Backspace","Delete","ArrowLeft","ArrowRight","ArrowUp","ArrowDown","Tab","Enter","Home","End","PageUp","PageDown"]; if(!nav.includes(event.key)&&!/^[0-9]$/.test(event.key)){event.preventDefault();} if(event.key==="0"&&this.value===""){event.preventDefault();}',
                'oninput'     => 'if(this.value!==""&&Number(this.value)<1)this.value="";',
            ]);

        $dataFilter = json_encode(['key' => 'operation_log_enable_automatic', 'value' => '1']);

        $allLabel = exmtrans('operation_log.schedule_all');

        $weekOptions = [1 => '月曜日', 2 => '火曜日', 3 => '水曜日', 4 => '木曜日', 5 => '金曜日', 6 => '土曜日', 7 => '日曜日'];

        $monthOptions = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthOptions[$m] = "{$m}月";
        }

        $dayOptions = array_combine(range(1, 31), range(1, 31));
        $hourOptions = array_combine(array_map('strval', range(0, 23)), range(0, 23));
        $minuteOptions = array_combine(array_map('strval', range(0, 59)), range(0, 59));

        $form->select('operation_log_automatic_week', exmtrans('operation_log.automatic_week'))
            ->options($weekOptions)
            ->placeholder($allLabel)
            ->help(exmtrans('operation_log.automatic_week_help'))
            ->attribute(['data-filter' => $dataFilter]);

        $form->select('operation_log_automatic_month', exmtrans('operation_log.automatic_month'))
            ->options($monthOptions)
            ->placeholder($allLabel)
            ->help(exmtrans('operation_log.automatic_month_help'))
            ->attribute(['data-filter' => $dataFilter]);

        $form->select('operation_log_automatic_day', exmtrans('operation_log.automatic_day'))
            ->options($dayOptions)
            ->placeholder($allLabel)
            ->help(exmtrans('operation_log.automatic_day_help'))
            ->attribute(['data-filter' => $dataFilter]);

        $form->select('operation_log_automatic_hour', exmtrans('operation_log.automatic_hour'))
            ->options($hourOptions)
            ->placeholder($allLabel)
            ->help(exmtrans('operation_log.automatic_hour_help'))
            ->attribute(['data-filter' => $dataFilter]);

        $form->select('operation_log_automatic_minute', exmtrans('operation_log.automatic_minute'))
            ->options($minuteOptions)
            ->placeholder($allLabel)
            ->help(exmtrans('operation_log.automatic_minute_help'))
            ->attribute(['data-filter' => $dataFilter]);

        return new Box(exmtrans('operation_log.enable_automatic'), $form);
    }

    /**
     * Save auto-delete settings.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function postSetting(Request $request)
    {
        if (!static::canManageLog()) {
            abort(403);
        }

        $autoEnabled = boolval($request->get('operation_log_enable_automatic', false));
        $keepDays = $request->get('operation_log_keep_days');

        // When auto-delete is enabled, keep_days is required and must be >= 1
        if ($autoEnabled) {
            $validator = Validator::make($request->all(), [
                'operation_log_keep_days' => 'required|integer|min:1',
            ]);

            if (!$validator->passes()) {
                return back()->withInput();
            }
        }

        System::operation_log_enable_automatic($autoEnabled);

        if (!is_null($keepDays) && $keepDays !== '') {
            System::operation_log_keep_days((int)$keepDays);
        }

        $scheduleFields = [
            'operation_log_automatic_week',
            'operation_log_automatic_month',
            'operation_log_automatic_day',
            'operation_log_automatic_hour',
            'operation_log_automatic_minute',
        ];
        foreach ($scheduleFields as $field) {
            $value = $request->get($field);
            System::$field($value !== '' && !is_null($value) ? $value : null);
        }

        admin_toastr(trans('admin.save_succeeded'));
        return redirect(admin_url('auth/logs'));
    }

    /**
     * Render diff_json as "column: before -> after" lines for the log grid.
     *
     * @param array<mixed>|null $diff
     * @return string
     */
    public static function formatAuditDiff($diff): string
    {
        if (!is_array($diff) || empty($diff)) {
            return '';
        }

        $html = [];
        foreach ($diff as $key => $item) {
            $before = json_encode(array_get($item, 'before'), JSON_UNESCAPED_UNICODE);
            $after = json_encode(array_get($item, 'after'), JSON_UNESCAPED_UNICODE);
            $html[] = '<div><b>' . esc_html($key) . '</b>: '
                . '<span class="text-muted">' . esc_html(mb_strimwidth((string)$before, 0, 60, '...')) . '</span>'
                . ' &rarr; '
                . '<span>' . esc_html(mb_strimwidth((string)$after, 0, 60, '...')) . '</span>'
                . '</div>';
        }

        return implode('', $html);
    }

    /**
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new OperationLog());

        $grid->model()->orderBy('id', 'DESC');

        $grid->column('user.user_name', exmtrans('operation_log.user_name'))->display(function ($foo, $column, $model) {
            return $model->user_name;
        });
        $grid->column('method', exmtrans('operation_log.method'));
        $grid->column('event_type', exmtrans('operation_log.event_type'));
        $grid->column('path', exmtrans('operation_log.path'));
        $grid->column('resource_type', exmtrans('operation_log.resource'))->display(function ($value, $column, $model) {
            if (is_nullorempty($model->resource_type)) {
                return '';
            }
            return esc_html($model->resource_type) . ' #' . intval($model->resource_id);
        });
        $grid->column('diff_json', exmtrans('operation_log.diff'))->display(function ($value, $column, $model) {
            return LogController::formatAuditDiff($model->diff_json);
        });
        $grid->column('ip', exmtrans('operation_log.ip'));
        $grid->column('created_at', trans('admin.created_at'));

        $canManage = static::canManageLog();
        $grid->actions(function (Grid\Displayers\Actions $actions) use ($canManage) {
            $actions->disableEdit();
            if (!$canManage) {
                $actions->disableDelete();
            }
        });

        if (!$canManage) {
            $grid->disableRowSelector();
        }
        $grid->disableCreateButton();
        $grid->disableExport();
        $grid->model()->with(['user', 'user.base_user']);

        $grid->filter(function (Grid\Filter $filter) {
            $userModel = config('admin.database.users_model');

            $filter->equal('user_id', exmtrans('operation_log.user_name'))->select($userModel::with(['base_user'])->get()->pluck('name', 'id'));
            $filter->equal('method', exmtrans('operation_log.method'))->select(array_combine(OperationLog::$methods, OperationLog::$methods));
            $filter->like('path', exmtrans('operation_log.path'));
            $filter->equal('event_type', exmtrans('operation_log.event_type'))
                ->select(['create' => 'create', 'update' => 'update', 'delete' => 'delete', 'view' => 'view']);
            $filter->like('resource_type', exmtrans('operation_log.resource'));
            $filter->equal('ip', exmtrans('operation_log.ip'));
            $filter->betweendatetime(function ($query, $input) {
                if (array_key_value_exists('start', $input)) {
                    $query->whereDateMarkExment('created_at', Carbon::parse($input['start']), '>=', true);
                }
                if (array_key_value_exists('end', $input)) {
                    $query->whereDateMarkExment('created_at', Carbon::parse($input['end']), '<=', true);
                }
            }, exmtrans('common.created_at'))->date();
        });

        // create exporter
        $service = $this->getImportExportService($grid);
        $grid->exporter($service);

        $grid->tools(function (Grid\Tools $tools) use ($grid) {
            $button = new Tools\ExportImportButton(admin_url('loginuser'), $grid, false, true, false);
            $button->setBaseKey('common');
            /** @phpstan-ignore-next-line append() expects ExmentAdminCore\Admin\Grid\Tools\AbstractTool|string, Exceedone\Exment\Form\Tools\ExportImportButton given */
            $tools->append($button);
        });

        return $grid;
    }

    /**
     * Make a show builder.
     *
     * @param mixed   $id
     * @return Show
     */
    protected function detail($id)
    {
        $model = OperationLog::findOrFail($id);
        // @phpstan-ignore-next-line
        return new Show($model, function (Show $show) {
            $show->field('user.user_name', exmtrans('operation_log.user_name'))->as(function ($foo, $model) {
                return ($model->user ? $model->user->user_name : null);
            });
            $show->field('method', exmtrans('operation_log.method'));
            $show->field('path', exmtrans('operation_log.path'));
            $show->field('ip', exmtrans('operation_log.ip'));
            $show->field('input', exmtrans('operation_log.input'))->as(function ($input) {
                $input = json_decode_ex($input, true);
                // @phpstan-ignore-next-line
                $input = Arr::except($input, ['_pjax', '_token', '_method', '_previous_']);
                if (empty($input)) {
                    return '{}';
                }

                return json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            });
            $show->field('created_at', trans('admin.created_at'));

            $show->panel()->tools(function ($tools) {
                $tools->disableEdit();
            });
        });
    }

    /**
     * @param mixed $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        if (!static::canManageLog()) {
            abort(403);
        }

        $ids = explode(',', $id);

        if (OperationLog::destroy(array_filter($ids))) {
            $data = [
                'status'  => true,
                'message' => trans('admin.delete_succeeded'),
            ];
        } else {
            $data = [
                'status'  => false,
                'message' => trans('admin.delete_failed'),
            ];
        }

        return response()->json($data);
    }

    // @phpstan-ignore-next-line
    protected function getImportExportService($grid = null)
    {
        // create exporter
        return (new DataImportExport\DataImportExportService())
            ->exportAction(new DataImportExport\Actions\Export\OperationLogAction(
                [
                    'grid' => $grid,
                ]
            ));
    }
}
