<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Controllers\HasResourceActions;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Form\Field\WorkFlow as WorkFlowField;

class WorkflowController extends AdminControllerBase
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
        // get request
        $request = Request::capture();
        if (!is_null($request->input('action'))) {
            $is_action = $request->input('action');
        } else {
            $is_action = $request->query('action')?? '0';
        }

        if ($is_action) {
            return $this->actionForm($id, $is_action);
        } else {
            return $this->statusForm($id, $is_action);
        }
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
        
        $form->text('start_status_name', exmtrans("workflow.start_status_name"))
            ->required()
            ->rules("max:30");
        $form->switchbool('start_datalock_flg', exmtrans("workflow.datalock_flg"))
            ->help(exmtrans('workflow.help.datalock_flg'))
            ->default(0);

        $form->hasManyTable('workflow_statuses', exmtrans("workflow.workflow_statuses"), function ($form) {
            $form->text('status_name', exmtrans("workflow.status_name"))->help(exmtrans('workflow.help.status_name'));
            $form->switchbool('datalock_flg', exmtrans("workflow.datalock_flg"))->help(exmtrans('workflow.help.editable_flg'));
        })->setTableColumnWidth(6, 2, 2)
            ->setTableWidth(8, 2)
            ->rowUpDown('order')
            ->description(sprintf(exmtrans("workflow.description_workflow_statuses")));
        
        $form->text('end_status_name', exmtrans("workflow.end_status_name"))
            ->required()
            ->rules("max:30");
        $form->switchbool('end_datalock_flg', exmtrans("workflow.datalock_flg"))
            ->help(exmtrans('workflow.help.datalock_flg'))
            ->default(1);

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
            'description' => exmtrans('workflow.workflow_statuses')
        ];
        $steps[] = [
            'active' => $is_action,
            'complete' => $hasAction,
            'url' => !$is_action? $workflow_action_url: null,
            'description' => exmtrans('workflow.workflow_actions')
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
        $workflow = Workflow::find($id);
        $form = new Form(new Workflow);
        $form->progressTracker()->options($this->getProgressInfo($id, $is_action));
        $form->hidden('action')->default(1);
        $form->display('workflow_name', exmtrans("workflow.workflow_name"));

        $form->pushField(new WorkFlowField\ActionHasMany('workflow_actions', [exmtrans("workflow.workflow_actions"), function($form) use($workflow){
            $form->text('action_name', exmtrans("workflow.action_name"));
            
            $form->statusSelects('status_start', exmtrans("workflow.status_name"))->options($workflow->getStatusOptions());

            $form->valueModal('work_targets', exmtrans("workflow.work_targets"))
                ->ajax(admin_urls('workflow/modal/target'))
                ->text(function ($value) {
                    // /////TODO:copy and paste
                    // if (!isset($value)) {
                    //     return null;
                    // }
                    // // convert json to array
                    // if (!is_array($value) && is_json($value)) {
                    //     $value = json_decode($value, true);
                    // }

                    // $custom_column_options = $self->getCalcCustomColumnOptions($id, $custom_table);
                    // ///// get text
                    // $texts = [];
                    // foreach ($value as &$v) {
                    //     $texts[] = $self->getCalcDisplayText($v, $custom_column_options);
                    // }
                    return null;
                })
            ;

           // $form->workTargets('work_targets', exmtrans("workflow.work_targets"));
        }]));

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

    public function modalTarget(Request $request){
        $form = new ModalForm;
        $form->disableReset();
        $form->disableSubmit();

        $form->select('aaa')->options(['1' => 'aaa']);

        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => exmtrans('common.shared')
        ]);
    }
}
