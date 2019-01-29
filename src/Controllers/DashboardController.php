<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Controllers\HasResourceActions;
use Encore\Admin\Widgets\Box;
//use Encore\Admin\Widgets\Form;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Form\Tools\DashboardMenu;
use Exceedone\Exment\Enums\RoleValue;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\UserSetting;

class DashboardController extends AdminControllerBase
{
    use HasResourceActions;
    protected $dashboard;

    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("dashboard.header"), exmtrans("dashboard.header"));
    }

    protected function setDashboardInfo(Request $request)
    {
        $this->dashboard = Dashboard::getDefault();
    }

    public function index(Request $request, Content $content)
    {
        return redirect(admin_base_path(''));
    }
    
    /**
     * Edit interface.
     *
     * @param $id
     * @return Content
     */
    public function edit(Request $request, $id, Content $content)
    {
        $this->setDashboardInfo($request);
        return parent::edit($request, $id, $content);
    }

    /**
     * Create interface.
     *
     * @return Content
     */
    public function create(Request $request, Content $content)
    {
        $this->setDashboardInfo($request);
        return parent::create($request, $content);
    }

    public function home(Request $request, Content $content)
    {
        // check permission. if not permission, show message
        if (\Exment::user()->noPermission()) {
            admin_warning(trans('admin.deny'), exmtrans('common.help.no_permission'));
        }

        $this->setDashboardInfo($request);
        $this->AdminContent($content);
        // add dashboard header
        $content->row((new DashboardMenu($this->dashboard))->render());

        //set row
        for ($i = 1; $i <= intval(config('exment.dashboard_rows', 4)); $i++) {
            $row_name = 'row'.$i;
            $row_column = intval($this->dashboard->getOption($row_name));
            if ($row_column > 0) {
                $this->setDashboardBox($content, $row_column, $i);
            }
        }

        // set dashboard box --------------------------------------------------
        $delete_confirm = trans('admin.delete_confirm');
        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');
        $script = <<<EOT
        $(function () {
            // get suuid inputs
            var suuids = $('[data-suuid]');
            // add 'row-eq-height' class
            suuids.parents('.row').addClass('row-eq-height row-dashboard');
            suuids.each(function(index, element){
                var suuid = $(element).data('suuid');
                loadDashboardBox(suuid);
            });

            ///// delete click event
            $('[data-exment-widget="delete"]').off('click').on('click', function(ev){
                // get suuid
                var suuid = $(ev.target).closest('[data-suuid]').data('suuid');
                swal({
                    title: "$delete_confirm",
                    type: "warning",
                    showCancelButton: true,
                    confirmButtonColor: "#DD6B55",
                    confirmButtonText: "$confirm",
                    closeOnConfirm: false,
                    allowOutsideClick: false,
                    cancelButtonText: "$cancel",
                    preConfirm: function() {
                        return new Promise(function(resolve) {
                            $.ajax({
                                method: 'post',
                                url: admin_base_path('dashboardbox/delete/' + suuid),
                                data: {
                                    _method:'delete',
                                    _token:LA.token
                                },
                                success: function (data) {
                                    $.pjax.reload('#pjax-container');
                    
                                    if (typeof data === 'object') {
                                        if (data.status) {
                                            swal(data.message, '', 'success');
                                        } else {
                                            swal(data.message, '', 'error');
                                        }
                                    }
                                }
                            });
                        });
                    }
                });
            });
            
            ///// reload click event
            $('[data-exment-widget="reload"]').off('click').on('click', function(ev){
                // get suuid
                var target = $(ev.target).closest('[data-suuid]');
                target.find('.box-body-inner-header,.box-body-inner-body').html('');
                target.find('.overlay').show();
                var suuid = $(ev.target).closest('[data-suuid]').data('suuid');
                loadDashboardBox(suuid);
            });
        });

        function loadDashboardBox(suuid){
            if(!hasValue(suuid)){
                return true;
            }
            var target = $('[data-suuid="' + suuid + '"]');
            if(target.hasClass('loading')){
                return true;
            }
            target.addClass('loading');
            $.ajax({
                url: admin_base_path('dashboardbox/html/' + suuid),
                type: "GET",
                success: function (data) {
                    var suuid = data.suuid;
                    var header = data.header;
                    var body = data.body;

                    // get target object
                    var target = $('[data-suuid="' + suuid + '"]');
                    target.find('.box-body-inner-header,.box-body-inner-body').html('');

                    // if set header
                    if(header){
                        target.find('.box-body .box-body-inner-header').html(header);
                    }
                    // if set body
                    if(body){
                        target.find('.box-body .box-body-inner-body').html(body);
                    }
                    target.find('.overlay').hide();
                    target.removeClass('loading');

                    Exment.CommonEvent.tableHoverLink();
                },
            });
        }
EOT;
        Admin::script($script);
        return $content;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        $form = new Form(new Dashboard);
        $form->hidden('dashboard_type')->default(DashboardType::SYSTEM);

        if (!isset($id)) {
            $form->text('dashboard_name', exmtrans("dashboard.dashboard_name"))
                ->required()
                ->rules("unique:".Dashboard::getTableName()."|regex:/".Define::RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN."/")
                ->help(exmtrans('common.help_code'));
        } else {
            $form->display('dashboard_name', exmtrans("dashboard.dashboard_name"));
        }

        $form->text('dashboard_view_name', exmtrans("dashboard.dashboard_view_name"))->required();

        // create row select options
        $form->embeds('options', exmtrans("dashboard.row"), function ($form) {
            for ($row_count = 1; $row_count <= intval(config('exment.dashboard_rows', 4)); $row_count++) {
                $row = [];
                if ($row_count > 1) {
                    $row[] = exmtrans('dashboard.row_options0');
                }
                for ($i = 1; $i <= 4; $i++) {
                    $row[$i] = $i.exmtrans('dashboard.row_optionsX');
                }

                // get default
                switch ($row_count) {
                    case 1:
                        $default = 1;
                        break;
                    case 2:
                        $default = 2;
                        break;
                    default:
                        $default = 0;
                        break;
                }

                $form->radio('row'.$row_count, sprintf(exmtrans("dashboard.row"), $row_count))
                    ->options($row)
                    ->help(sprintf(exmtrans("dashboard.description_row"), $row_count))
                    ->required()
                    ->default($default);
            }
        })->disableHeader();

        $form->switchbool('default_flg', exmtrans("common.default"))->default(false);

        disableFormFooter($form);
        $form->tools(function (Form\Tools $tools) use ($id, $form) {
            $tools->disableView();
            $tools->disableList();

            // addhome button
            $tools->append('<a href="'.admin_base_path('').'" class="btn btn-sm btn-default"  style="margin-right: 5px"><i class="fa fa-home"></i>&nbsp;'. exmtrans('common.home').'</a>');
        });

        $form->saved(function ($form) {
            // get form model
            $model = $form->model();
            if (isset($model)) {
                // set setting value
                Admin::user()->setSettingValue(UserSetting::DASHBOARD, array_get($model, 'suuid'));
            }
        });

        return $form;
    }

    protected function setDashboardBox($content, $row_column_count, $row_no)
    {
        $content->row(function ($row) use ($content, $row_column_count, $row_no) {
            // check role.
            //TODO:now system admin. change if user dashboard
            $has_role = Admin::user()->hasPermission(RoleValue::SYSTEM);
            for ($i = 1; $i <= $row_column_count; $i++) {
                $func = "dashboard_row{$row_no}_boxes";
                // get $boxes as $row_no
                $boxes = $this->dashboard->{$func}();

                // get target column by database
                $dashboard_column = $boxes->where('column_no', $i)->first();
                $id = $dashboard_column->id ?? null;

                // new dashboadbox dropdown button list
                $dashboardboxes_newbuttons = [];
                if ($has_role) {
                    foreach (DashboardBoxType::DASHBOARD_BOX_TYPE_OPTIONS() as $options) {
                        // create query
                        $query = http_build_query([
                            'dashboard_suuid' => $this->dashboard->suuid,
                            'dashboard_box_type' => array_get($options, 'dashboard_box_type'),
                            'row_no' => $row_no,
                            'column_no' => $i,
                        ]);
                        $dashboardboxes_newbuttons[] = [
                            'url' => admin_base_path("dashboardbox/create?{$query}"),
                            'icon' =>  $options['icon'],
                            'view_name' => exmtrans("dashboard.dashboard_box_type_options.{$options['dashboard_box_type']}"),
                        ];
                    }
                }

                // right-top icons
                $icons = [['widget' => 'reload', 'icon' => 'fa-refresh']];
                // check role.
                if ($has_role) {
                    $icons = array_prepend($icons, ['link' => admin_base_path('dashboardbox/'.$id.'/edit'), 'icon' => 'fa-cog']);
                    array_push($icons, ['widget' => 'delete', 'icon' => 'fa-trash']);
                }
                
                // set column. use grid system
                $grids = [
                    'xs' => 12,
                    'md' => ($row_column_count == 0 ? 12 : 12 / $row_column_count)
                ];

                $row->column($grids, view('exment::dashboard.box', [
                    'title' => $dashboard_column->dashboard_box_view_name ?? null,
                    'id' => $id,
                    'suuid' => $dashboard_column->suuid ?? null,
                    'dashboard_suuid' => $this->dashboard->suuid,
                    'dashboardboxes_newbuttons' => $dashboardboxes_newbuttons,
                    'icons' => $icons,
                ]));
            }
        });
    }
}
