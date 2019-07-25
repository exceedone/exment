<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Controllers\HasResourceActions;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowStatusBlock;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\WorkflowStatusType;


class Workflow2Controller extends AdminControllerBase
{
    use HasResourceActions;

    protected $exists = false;

    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("workflow.header"), exmtrans("workflow.header"), exmtrans("workflow.description"), 'fa-share-alt');
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new Workflow);
        $grid->column('id', exmtrans("common.id"));
        $grid->column('workflow_name', exmtrans("workflow.workflow_name"))->sortable();
        
        $grid->disableExport();
        if (!\Exment::user()->hasPermission(Permission::SYSTEM)) {
            $grid->disableCreateButton();
        }

        $grid->actions(function (Grid\Displayers\Actions $actions) {
            if (CustomTable::where('workflow_id', $actions->row->id)->exists()) {
                $actions->disableDelete();
            }
            $actions->disableView();
            if (count($actions->row->workflow_statuses) > 0) {
                // add new edit link
                $linker = (new Linker)
                    ->url(admin_urls('workflow', $actions->getKey(), 'edit?action=1'))
                    ->icon('fa-link')
                    ->tooltip(exmtrans('workflow.action'));
                $actions->prepend($linker);
            }
        });

        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        $workflow = isset($id) ? Workflow::findOrFail($id) : new Workflow;

        // get workflow_statuses groupby
        $workflow_statuses = $this->getWorkflowStatusGroup($workflow);

        $form = new Form(new Workflow);

        $form->text('workflow_name', exmtrans("workflow.workflow_name"))
            ->required()
            ->rules("max:40");

        $form->exmheader(exmtrans("workflow.workflow_statuses"))->hr();

        // get exment version
        $ver = getExmentCurrentVersion() ?? date('YmdHis');
        $form->html(view('exment::workflow.workflow', [
            'css' => asset('/vendor/exment/css/workflow.css?ver='.$ver),
            'js' => asset('/vendor/exment/js/customform.js?ver='.$ver),
            'workflow_statuses' => $workflow_statuses,
        ]))->setWidth(12, 0);

        if (isset($id) && CustomTable::where('workflow_id', $id)->count() > 0) {
            $form->tools(function (Form\Tools $tools) {
                $tools->disableDelete();
            });
        }

        $form->saving(function (Form $form) {
            $this->exists = $form->model()->exists;
        });

        $form->saved(function (Form $form) use ($id) {
            // create or drop index --------------------------------------------------
            $model = $form->model();

            // redirect workflow action page
            if (!$this->exists) {
                $workflow_action_url = admin_urls('workflow', $model->id, 'edit?action=1');
    
                admin_toastr(exmtrans('workflow.help.saved_redirect_column'));
                return redirect($workflow_action_url);
            }
        });

        return $form;
    }

    /**
     * Get workflow statuses. group by status_group_id.
     *
     * @param Workflow $workflow
     * @return array workflow statuses
     */
    protected function getWorkflowStatusGroup($workflow){
        // get workflow statuses group by database
        $workflow_statuses_groups = $workflow->workflow_statuses()
            ->with(['workflow_status_blocks'])
            ->groupBy('workflow_group_id')
            ->orderBy('status_type')
            ->orderBy('workflow_group_id')
            ->get();

        // loop $workflow_statuses_groups
        $workflow_statuses = [];
        foreach(WorkflowStatusType::values() as $workflow_status_type_enum){
            // filter $workflow_statuses_groups
            $workflow_statuses_group_filter = $workflow_statuses_groups->filter(function($workflow_statuses_group) use($workflow_status_type_enum){
                return $workflow_statuses_group->status_type == $workflow_status_type_enum;
            });

            $enumOptions = $workflow_status_type_enum->getOption(['id' => $workflow_status_type_enum->getValue()]);

            $workflow_status_types = [];
            for($i = 0; $i < $enumOptions['count']; $i++){
                // if contains item, get 
                if($i < count($workflow_statuses_group_filter)){
                    $workflow_status_type = $workflow_statuses_group_filter->values()->get($i);
                }
                // else, create new item
                else{
                    $workflow_status_type = new WorkflowStatus;
                    $workflow_status_type->status_type = array_get($enumOptions, 'id');
                    $workflow_status_type->workflow_group_id = $i;
                    $workflow_status_type->enabled_flg = array_get($enumOptions, 'enabled_flg');

                    $status_name_trans = array_get($enumOptions, 'status_name_trans');
                    $workflow_status_type->status_name = isset($status_name_trans) ? exmtrans($status_name_trans) : null;
                }

                for ($j = 0; $j < $enumOptions['count']; $j++) {
                    // if not contains item, add
                    if($j >= count($workflow_status_type->workflow_status_blocks)){
                        $workflow_status_block = new WorkflowStatusBlock;
                        $workflow_status_type->workflow_status_blocks->push($workflow_status_block);
                    }
                }

                $workflow_status_types[] = $workflow_status_type;
            }

            $workflow_statuses[] = $workflow_status_types;
        }
        
        return $workflow_statuses;
    }

    /**
     * Make a action edit form builder.
     *
     * @return Form
     */
    public function action(Request $request, Content $content, $id)
    {
        return $this->AdminContent($content)->body($this->actionForm($id)->edit($id));
    }

    /**
     * Make a action edit form builder.
     *
     * @return Form
     */
    protected function statusForm($id, $is_action)
    {
        $form = new Form(new Workflow);
        $form->progressTracker()->options($this->getProgressInfo($id, $is_action));
        $form->text('workflow_name', exmtrans("workflow.workflow_name"))
            ->required()
            ->rules("max:40");

        $form->hasManyTable('workflow_statuses', exmtrans("workflow.workflow_statuses"), function ($form) {
            $form->text('status_name', exmtrans("workflow.status_name"));
            $form->switchbool('editable_flg', exmtrans("workflow.editable_flg"));
        })->setTableColumnWidth(8, 2, 2)
        ->description(sprintf(exmtrans("workflow.description_workflow_statuses")));
        
        if (isset($id) && CustomTable::where('workflow_id', $id)->count() > 0) {
            $form->tools(function (Form\Tools $tools) {
                $tools->disableDelete();
            });
        }

        $form->saving(function (Form $form) {
            $this->exists = $form->model()->exists;
        });

        $form->saved(function (Form $form) use ($id) {
            // create or drop index --------------------------------------------------
            $model = $form->model();

            // redirect workflow action page
            if (!$this->exists) {
                $workflow_action_url = admin_urls('workflow', $model->id, 'edit?action=1');
    
                admin_toastr(exmtrans('workflow.help.saved_redirect_column'));
                return redirect($workflow_action_url);
            }
        });

        return $form;
    }

    protected function getProgressInfo($id, $is_action) {
        $steps = [];
        $hasAction = false;
        $hasStatus = false;
        $workflow_action_url = null;
        $workflow_status_url = null;
        if (isset($id)) {
            $hasAction = WorkflowAction::where('workflow_id', $id)->count() > 0;
            $hasStatus = WorkflowStatus::where('workflow_id', $id)->count() > 0;
            $workflow_action_url = admin_urls('workflow', $id, 'edit?action=1');
            $workflow_status_url = admin_urls('workflow', $id, 'edit');
        }
        $steps[] = [
            'active' => !$is_action,
            'complete' => $hasStatus,
            'url' => $is_action? $workflow_status_url: null,
            'description' => '状態の定義'
        ];
        $steps[] = [
            'active' => $is_action,
            'complete' => $hasAction,
            'url' => !$is_action? $workflow_action_url: null,
            'description' => 'アクションの設定'
        ];
        return $steps;
    }

    /**
     * Make a action edit form builder.
     *
     * @return Form
     */
    protected function actionForm($id, $is_action)
    {
        $form = new Form(new Workflow);
        $form->progressTracker()->options($this->getProgressInfo($id, $is_action));
        $form->hidden('action')->default(1);
        $form->display('workflow_name', exmtrans("workflow.workflow_name"));

        $statuses = WorkflowStatus::where('workflow_id', $id)->get()->pluck('status_name', 'id');
        $statuses->prepend(exmtrans("workflow.status_init"), 0);

        $form->hasMany('workflow_actions', exmtrans("workflow.workflow_actions"), function ($form) use($id, $statuses) {
            $form->text('action_name', exmtrans("workflow.action_name"))->required();
            $form->select('status_from', exmtrans("workflow.status_from"))->required()
                ->options($statuses);
            $form->select('status_to', exmtrans("workflow.status_to"))->required()
                ->options($statuses);
            $form->hidden('workflow_id')->default($id);
            $form->multipleSelect('has_autority_users', exmtrans("workflow.has_autority_users"))
                ->options(function() {
                    return CustomTable::getEloquent(SystemTableName::USER)->getOptions();
                }
            );
            $form->multipleSelect('has_autority_organizations', exmtrans("workflow.has_autority_organizations"))
                ->options(function() {
                    return CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getOptions();
                }
            );
        });

        $form->tools(function (Form\Tools $tools) {
            $tools->disableDelete();
        });

        $form->ignore(['action']);

        $form->saving(function (Form $form) {
            if (!is_null($form->workflow_actions)) {
                $actions = collect($form->workflow_actions)->filter(function ($value) {
                    return $value[Form::REMOVE_FLAG_NAME] != 1;
                });
                foreach($actions as $action) {
                    if (array_get($action, 'status_from') == array_get($action, 'status_to')) {
                        admin_toastr(exmtrans('workflow.message.status_nochange'), 'error');
                        return back()->withInput();
                    }
                }
            }
        });

        return $form;
    }

    /**
     * validate before delete.
     */
    protected function validateDestroy($id)
    {
        // check referenced from customtable
        $refer_count = CustomTable::where('workflow_id', $id)
            ->count();

        if ($refer_count > 0) {
            return [
                'status'  => false,
                'message' => exmtrans('workflow.message.reference_error'),
            ];
        }
    }
}
