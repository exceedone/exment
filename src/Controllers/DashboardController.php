<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Controllers\ModelForm;
use Encore\Admin\Widgets\Box;
//use Encore\Admin\Widgets\Form;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Form\Tools\DashboardMenu;

class DashboardController extends AdminControllerBase
{
    use ModelForm;
    protected $dashboard;

    public function __construct(Request $request){
        $this->setPageInfo(exmtrans("dashboard.header"), exmtrans("dashboard.header"));
    }

    protected function setDashboardInfo(Request $request){
        // get admin_user
        $admin_user = Admin::user();

        // get dashboard using query
        if(!is_null($request->input('dashboard'))){
            $suuid = $request->input('dashboard');
            // if query has view id, set form.
            $this->dashboard = Dashboard::findBySuuid($suuid);
            // set suuid
            if (!is_null($admin_user)) {
                $admin_user->setSettingValue(Define::USER_SETTING_DASHBOARD, $suuid);
            }
        }
        // if url doesn't contain dashboard query, get dashboard user setting.
        if(is_null($this->dashboard) && !is_null($admin_user)){
            // get suuid
            $suuid = $admin_user->getSettingValue(Define::USER_SETTING_DASHBOARD);
            $this->dashboard = Dashboard::findBySuuid($suuid);
        }
        // if null, get dashboard first.
        if(is_null($this->dashboard)){
            $this->dashboard = Dashboard::first();
        }

        if(is_null($this->dashboard)){
            $this->setDefaultDashboard();
        }
    }

    public function index()
    {
        return redirect(admin_base_path(''));
    }
    
    /**
     * Edit interface.
     *
     * @param $id
     * @return Content
     */
    public function edit(Request $request, $id)
    {
        $this->setDashboardInfo($request);
        return $this->AdminContent(function (Content $content) use ($id) {
            $content->body($this->form($id)->edit($id));
        });
    }

    /**
     * Create interface.
     *
     * @return Content
     */
    public function create(Request $request)
    {
        $this->setDashboardInfo($request);
        return $this->AdminContent(function (Content $content) {
            $content->body($this->form());
        });
    }

    public function home(Request $request)
    {
        $this->setDashboardInfo($request);
        return $this->AdminContent(function (Content $content) {

            // add dashboard header
            $content->row((new DashboardMenu($this->dashboard))->render());

            //set row1
            $row1_column = intval($this->dashboard->row1);
            $this->setDashboardBox($content, $row1_column, 1);

            //set row2
            $row2_column = intval($this->dashboard->row2);
            if($row2_column > 0){
                $this->setDashboardBox($content, $row2_column, 2);
            }

            // set dashboard box --------------------------------------------------
            $delete_confirm = trans('admin.delete_confirm');
            $confirm = trans('admin.confirm');
            $cancel = trans('admin.cancel');
        $script = <<<EOT
        $(function () {
            // get suuid inputs
            var suuids = $('[data-suuid]');
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
                    cancelButtonText: "$cancel"
                  },
                  function(){
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
            });
            
            ///// reload click event
            $('[data-exment-widget="reload"]').off('click').on('click', function(ev){
                // get suuid
                var target = $(ev.target).closest('[data-suuid]');
                target.find('.box-body .box-body-inner').html('');
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
                    var html = data.html;

                    // get target object
                    var target = $('[data-suuid="' + suuid + '"]');
                    target.find('.box-body .box-body-inner').html(html);
                    target.find('.overlay').hide();
                    target.removeClass('loading');
                },
            });
        }
EOT;
            Admin::script($script);
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        return Admin::form(Dashboard::class, function (Form $form) use($id){
            $form->hidden('dashboard_type')->default('system');

            if(!isset($id)){
                $form->text('dashboard_name', exmtrans("dashboard.dashboard_name"))->rules("required|unique:".Dashboard::getTableName()."|regex:/".Define::RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN."/")
                    ->help(exmtrans('common.help_code'));
            }else{
                $form->display('dashboard_name', exmtrans("dashboard.dashboard_name"));
            }

            $form->text('dashboard_view_name', exmtrans("dashboard.dashboard_view_name"))->rules("required");
            
            // create row1 select options
            $row1 = [];
            for($i = 1; $i <= 4; $i++){
                $row1[$i] = $i.exmtrans('dashboard.row_optionsX');
            }
            $form->radio('row1', exmtrans("dashboard.row1"))
                ->options($row1)
                ->help(exmtrans("dashboard.description_row1"))
                ->rules("required")
                ->default(1);

            // create row2 select options
            $row2 = [];
            $row2[0] = exmtrans('dashboard.row_options0');
            for($i = 1; $i <= 4; $i++){
                $row2[$i] = $i.exmtrans('dashboard.row_optionsX');
            }
            $form->radio('row2', exmtrans("dashboard.row2"))
                ->options($row2)
                ->help(exmtrans("dashboard.description_row2"))
                ->rules("required")
                ->default(2);

            $form->disableReset();
            $form->disableViewCheck();
            
            $form->tools(function (Form\Tools $tools) use($id, $form) {
                $tools->disableView();
                $tools->disableList();

                // addhome button
                $tools->append('<a href="'.admin_base_path('').'" class="btn btn-sm btn-default"  style="margin-right: 5px"><i class="fa fa-home"></i>&nbsp;'. exmtrans('common.home').'</a>');
            });
        });
    }

    /**
     * set default dashboard
     */
    protected function setDefaultDashboard(){
        $this->dashboard = new Dashboard;
        $this->dashboard->dashboard_type = 'system';
        $this->dashboard->dashboard_name = 'system_default_dashboard';
        $this->dashboard->dashboard_view_name = exmtrans('dashboard.default_dashboard_name');
        $this->dashboard->row1 = 1;
        $this->dashboard->row2 = 2;
        $this->dashboard->save();
    }

    protected function setDashboardBox($content, $row_column_count, $row_no){
        $content->row(function($row) use($content, $row_column_count, $row_no) {
            for($i = 1; $i <= $row_column_count; $i++){
                // get $boxes as $row_no
                if($row_no == 1){
                    $boxes = $this->dashboard->dashboard_row1_boxes();
                }else{
                    $boxes = $this->dashboard->dashboard_row2_boxes();
                }

                // get target column by database
                $dashboard_column = $boxes->where('column_no', $i)->first();

                // new dashboadbox dropdown button list
                $dashboardboxes_newbuttons = [];
                foreach(Define::DASHBOARD_BOX_TYPE_OPTIONS as $options){
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
                $box = new Box();
                $row->column(12 / $row_column_count, view('exment::dashboard.box', [
                    'title' => $dashboard_column->dashboard_box_view_name ?? null,
                    'id' => $dashboard_column->id ?? null,
                    'suuid' => $dashboard_column->suuid ?? null,
                    'dashboard_suuid' => $this->dashboard->suuid,
                    'dashboardboxes_newbuttons' => $dashboardboxes_newbuttons,
                ]));
            }
        });
    }
}
