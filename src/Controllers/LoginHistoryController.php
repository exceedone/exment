<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\LoginHistory;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\DataImportExport;
use Exceedone\Exment\Services\GeoIp\GeoIpService;
use ExmentAdminCore\Admin\Grid;
use ExmentAdminCore\Admin\Layout\Content;
use ExmentAdminCore\Admin\Show;
use ExmentAdminCore\Admin\Widgets\Box;
use ExmentAdminCore\Admin\Widgets\Form as WidgetForm;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Validator;

class LoginHistoryController extends AdminControllerBase
{
    /**
     * Upper limit of the setting "number of recent logins to compare".
     */
    public const NEW_IP_COUNT_MAX = 1000;

    public function __construct()
    {
        $this->setPageInfo(exmtrans('login_history.header'), exmtrans('login_history.header'), exmtrans('login_history.description'), 'fa-history');
    }

    /**
     * Index interface with settings box.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);
        $content->body($this->grid());
        $content->row($this->settingFormBox());
        return $content;
    }

    /**
     * Build the settings form box. (warning notification, auto-delete, GeoIP database)
     *
     * @return Box
     */
    protected function settingFormBox()
    {
        // Merge old input (flashed by back()->withInput() on validation error)
        // so the form retains the user's submitted values instead of resetting to saved values.
        $formData = System::get_system_values(['login_history']);
        $oldInput = session()->getOldInput();
        if (!empty($oldInput)) {
            $formData = array_merge($formData, $oldInput);
        }

        $form = new WidgetForm($formData);
        $form->action(admin_urls('login_history', 'setting'));
        $form->disableReset();

        $form->exmheader(exmtrans('login_history.notify.header'))->hr();

        $form->descriptionHtml(esc_html(exmtrans('login_history.help.notify')));

        $form->number('login_history_new_ip_count', exmtrans('login_history.new_ip_count'))
            ->help(exmtrans('login_history.help.new_ip_count', static::NEW_IP_COUNT_MAX))
            ->min(1)
            ->max(static::NEW_IP_COUNT_MAX);

        $form->switchbool('login_history_notify_new_ip', exmtrans('login_history.notify.enable'))
            ->help(exmtrans('login_history.help.notify_new_ip'))
            ->attribute(['data-filtertrigger' => true]);

        $form->switchbool('login_history_notify_mail', exmtrans('login_history.notify.mail'))
            ->help(exmtrans('login_history.help.notify_mail'))
            ->attribute([
                'data-filter' => json_encode(['key' => 'login_history_notify_new_ip', 'value' => '1']),
            ]);

        $form->exmheader(exmtrans('login_history.enable_automatic'))->hr();

        $form->switchbool('login_history_enable_automatic', exmtrans('login_history.enable_automatic'))
            ->help(exmtrans('login_history.help.enable_automatic'))
            ->attribute(['data-filtertrigger' => true]);

        $form->number('login_history_keep_days', exmtrans('login_history.keep_days'))
            ->help(exmtrans('login_history.help.keep_days'))
            ->min(1)
            ->attribute([
                'data-filter' => json_encode(['key' => 'login_history_enable_automatic', 'value' => '1']),
            ]);

        $form->exmheader(exmtrans('login_history.geoip_database'))->hr();

        $form->descriptionHtml($this->getGeoIpDescriptionHtml());

        $form->switchbool('login_history_geoip_auto_update', exmtrans('login_history.geoip_auto_update'))
            ->help(exmtrans('login_history.help.geoip_auto_update'));

        return new Box(exmtrans('login_history.setting'), $form);
    }

    /**
     * Get description of the GeoIP database status.
     *
     * @return string
     */
    protected function getGeoIpDescriptionHtml(): string
    {
        $info = GeoIpService::getDatabaseInfo();
        if (is_null($info)) {
            return esc_html(exmtrans('login_history.help.geoip_not_available'));
        }

        $html = esc_html(exmtrans('login_history.help.geoip_available', $info['type'], $info['build_date']->format('Y-m-d')));
        // DB-IP Lite database is licensed under CC BY 4.0. Attribution is required.
        if (stripos($info['type'], 'DBIP') !== false) {
            $html .= '<br /><a href="https://db-ip.com" target="_blank" rel="noopener noreferrer">IP Geolocation by DB-IP</a>';
        }

        return $html;
    }

    /**
     * Save settings.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function postSetting(Request $request)
    {
        $autoEnabled = boolval($request->get('login_history_enable_automatic', false));
        $keepDays = $request->get('login_history_keep_days');
        $newIpCount = $request->get('login_history_new_ip_count');

        $validator = Validator::make($request->all(), [
            'login_history_new_ip_count' => 'nullable|integer|min:1|max:' . static::NEW_IP_COUNT_MAX,
            'login_history_keep_days' => ($autoEnabled ? 'required' : 'nullable') . '|integer|min:1',
        ]);
        if (!$validator->passes()) {
            $messageKey = $validator->errors()->has('login_history_new_ip_count') ? 'new_ip_count_invalid' : 'keep_days_invalid';
            admin_toastr(exmtrans("login_history.message.{$messageKey}", static::NEW_IP_COUNT_MAX), 'error');
            return back()->withInput();
        }

        // Empty number keeps the saved value.
        if (!is_nullorempty($newIpCount)) {
            System::login_history_new_ip_count((int)$newIpCount);
        }
        System::login_history_notify_new_ip(boolval($request->get('login_history_notify_new_ip', false)));
        System::login_history_notify_mail(boolval($request->get('login_history_notify_mail', false)));

        System::login_history_enable_automatic($autoEnabled);
        if (!is_nullorempty($keepDays)) {
            System::login_history_keep_days((int)$keepDays);
        }
        System::login_history_geoip_auto_update(boolval($request->get('login_history_geoip_auto_update', false)));

        admin_toastr(trans('admin.save_succeeded'));
        return redirect(admin_url('login_history'));
    }

    /**
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new LoginHistory());

        $grid->model()->orderBy('id', 'DESC');

        $grid->column('created_at', exmtrans('login_history.login_at'))->sortable();
        $grid->column('user_code', exmtrans('login_history.user_code'));
        $grid->column('user_name', exmtrans('login_history.user_name'));
        $grid->column('ip_address', exmtrans('login_history.ip_address'));
        $grid->column('country', exmtrans('login_history.country'))->display(function ($value, $column, $model) {
            return $model->country_text;
        })->escape(true);
        $grid->column('location', exmtrans('login_history.location'))->display(function ($value, $column, $model) {
            return $model->location;
        })->escape(true);
        $grid->column('login_type', exmtrans('login_history.login_type'))->display(function ($value, $column, $model) {
            return $model->login_type_text;
        })->escape(true);
        $grid->column('is_new_ip', exmtrans('login_history.is_new_ip'))->display(function ($value) {
            if (!boolval($value)) {
                return '';
            }
            return '<span class="label label-danger">' . esc_html(exmtrans('login_history.is_new_ip_options.1')) . '</span>';
        });
        $grid->column('auth_2factor_verified', exmtrans('login_history.auth_2factor_verified'))->display(function ($value) {
            if (is_null($value)) {
                return '';
            }
            $verified = boolval($value);
            return '<span class="label label-' . ($verified ? 'success' : 'warning') . '">'
                . esc_html(exmtrans('login_history.auth_2factor_verified_options.' . ($verified ? '1' : '0'))) . '</span>';
        });

        // Same as the operation log: show / delete per row, checkbox for batch delete, no edit.
        // (The row selector and the delete link also make the whole row clickable. See CommonEvent.tableHoverLink)
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableEdit();
        });

        $grid->disableCreateButton();
        $grid->disableExport();

        $grid->filter(function (Grid\Filter $filter) {
            $filter->disableIdFilter();

            $filter->like('user_code', exmtrans('login_history.user_code'));
            $filter->like('user_name', exmtrans('login_history.user_name'));
            $filter->equal('ip_address', exmtrans('login_history.ip_address'));
            $filter->equal('country_code', exmtrans('login_history.country'))->select(LoginHistoryController::getCountryOptions());
            $filter->equal('is_new_ip', exmtrans('login_history.is_new_ip'))->select([
                1 => exmtrans('login_history.is_new_ip_options.1'),
                0 => exmtrans('login_history.is_new_ip_options.0'),
            ]);
            $filter->betweendatetime(function ($query, $input) {
                if (array_key_value_exists('start', $input)) {
                    $query->whereDateMarkExment('created_at', Carbon::parse($input['start']), '>=', true);
                }
                if (array_key_value_exists('end', $input)) {
                    $query->whereDateMarkExment('created_at', Carbon::parse($input['end']), '<=', true);
                }
            }, exmtrans('login_history.login_at'))->date();
        });

        // create exporter
        $service = $this->getImportExportService($grid);
        $grid->exporter($service);

        $grid->tools(function (Grid\Tools $tools) use ($grid) {
            $button = new Tools\ExportImportButton(admin_url('login_history'), $grid, false, true, false);
            $button->setBaseKey('common');
            /** @phpstan-ignore-next-line append() expects ExmentAdminCore\Admin\Grid\Tools\AbstractTool|string, Exceedone\Exment\Form\Tools\ExportImportButton given */
            $tools->append($button);
        });

        return $grid;
    }

    /**
     * Show the detail. The history may have been deleted (manually or by the auto-delete) after the warning
     * notification or mail linking to it was sent: go to the list with the "data not found" message, not an error page.
     *
     * @param Request $request
     * @param Content $content
     * @param mixed $id
     * @return Content|\Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function show(Request $request, Content $content, $id)
    {
        if (!LoginHistory::where('id', $id)->exists()) {
            admin_toastr(exmtrans('common.message.notfound'), 'error');
            return redirect(admin_url('login_history'));
        }
        return parent::show($request, $content, $id);
    }

    /**
     * Make a show builder.
     *
     * @param mixed   $id
     * @return Show
     */
    protected function detail($id)
    {
        $model = LoginHistory::findOrFail($id);
        // @phpstan-ignore-next-line
        return new Show($model, function (Show $show) {
            $show->field('created_at', exmtrans('login_history.login_at'));
            $show->field('user_code', exmtrans('login_history.user_code'));
            $show->field('user_name', exmtrans('login_history.user_name'));
            $show->field('ip_address', exmtrans('login_history.ip_address'));
            $show->field('country', exmtrans('login_history.country'))->as(function ($value, $model) {
                return $model->country_text;
            });
            $show->field('region', exmtrans('login_history.region'));
            $show->field('city', exmtrans('login_history.city'));
            $show->field('login_type', exmtrans('login_history.login_type'))->as(function ($value, $model) {
                return $model->login_type_text;
            });
            $show->field('via_remember', exmtrans('login_history.via_remember'))->as(function ($value) {
                return boolval($value) ? 'YES' : 'NO';
            });
            $show->field('is_new_ip', exmtrans('login_history.is_new_ip'))->as(function ($value) {
                return exmtrans('login_history.is_new_ip_options.' . (boolval($value) ? '1' : '0'));
            });
            $show->field('auth_2factor_verified', exmtrans('login_history.auth_2factor_verified'))->as(function ($value) {
                if (is_null($value)) {
                    return exmtrans('login_history.auth_2factor_verified_options.none');
                }
                return exmtrans('login_history.auth_2factor_verified_options.' . (boolval($value) ? '1' : '0'));
            });
            $show->field('user_agent', exmtrans('login_history.user_agent'));

            $show->panel()->tools(function ($tools) {
                $tools->disableEdit();
            });
        });
    }

    /**
     * Delete histories. $id is a single id, or ids joined by "," (batch delete).
     *
     * @param mixed $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $ids = array_filter(explode(',', (string)$id), function ($id) {
            return is_numeric($id);
        });

        if (count($ids) > 0 && LoginHistory::destroy($ids)) {
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

    /**
     * Get countries in the histories, for filter options.
     *
     * @return array<string, string> key: country code, value: country text
     */
    public static function getCountryOptions(): array
    {
        return LoginHistory::query()
            ->whereNotNull('country_code')
            ->select(['country_code', 'country'])
            ->distinct()
            ->get()
            ->mapWithKeys(function ($row) {
                return [$row->country_code => $row->country_text];
            })
            ->sort()
            ->toArray();
    }

    // @phpstan-ignore-next-line
    protected function getImportExportService($grid = null)
    {
        // create exporter
        return (new DataImportExport\DataImportExportService())
            ->exportAction(new DataImportExport\Actions\Export\LoginHistoryAction(
                [
                    'grid' => $grid,
                ]
            ));
    }
}
