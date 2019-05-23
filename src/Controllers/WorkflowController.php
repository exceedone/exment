<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
// use Encore\Admin\Controllers\HasResourceActions;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowStatus;
// use Exceedone\Exment\Model\Role;
// use Exceedone\Exment\Model\Define;
// use Exceedone\Exment\Model\Menu;
// use Exceedone\Exment\Form\Tools;
// use Exceedone\Exment\Enums\MenuType;
// use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\Permission;

class WorkflowController extends AdminControllerBase
{
    use HasResourceActions, RoleForm;

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
            return $this->actionForm($id);
        } else {
            return $this->statusForm($id);
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
    protected function statusForm($id)
    {
        $form = new Form(new Workflow);
        $form->text('workflow_name', exmtrans("workflow.workflow_name"))
            ->required()
            ->rules("max:40");

        $form->hasManyTable('workflow_statuses', exmtrans("workflow.workflow_statuses"), function ($form) {
            $form->text('status_name', exmtrans("workflow.status_name"));
            $form->switchbool('editable_flg', exmtrans("workflow.editable_flg"));
        })->setTableColumnWidth(8, 2, 2)
        ->description(sprintf(exmtrans("workflow.description_workflow_statuses")));
        
        $form->saving(function (Form $form) {
            $this->exists = $form->model()->exists;
        });

        $form->saved(function (Form $form) use ($id) {
            // create or drop index --------------------------------------------------
            $model = $form->model();

            // redirect workflow action page
            if (!$this->exists) {
                $workflow_action_url = admin_urls('workflow', $model->id, 'action');
    
                admin_toastr(exmtrans('workflow.help.saved_redirect_column'));
                return redirect($workflow_action_url);
            }
        });

        return $form;
    }

    /**
     * Make a action edit form builder.
     *
     * @return Form
     */
    protected function actionForm($id)
    {
        $form = new Form(new Workflow);
        $form->hidden('action')->default(1);
        $form->display('workflow_name', exmtrans("workflow.workflow_name"));

        $statuses = WorkflowStatus::where('workflow_id', $id)->get()->pluck('status_name', 'id');
        $statuses->prepend(exmtrans("workflow.status_init"), 0);

        $form->hasManyTable('workflow_actions', exmtrans("workflow.workflow_actions"), function ($form) use($id, $statuses) {
            $form->text('action_name', exmtrans("workflow.action_name"))->required();
            $form->select('status_from', exmtrans("workflow.status_from"))->required()
                ->options($statuses);
            $form->select('status_to', exmtrans("workflow.status_to"))->required()
                ->options($statuses);
            $form->hidden('workflow_id')->default($id);
        })->setTableColumnWidth(4, 3, 3, 2)
        ->description(sprintf(exmtrans("workflow.description_workflow_actions")));

        $form->tools(function (Form\Tools $tools) use ($form, $id) {
            $tools->disableDelete();
        });

        $form->ignore('action');

        // $form->saving(function (Form $form) {
        //     $this->exists = $form->model()->exists;
        // });

        // $form->saved(function (Form $form) use ($id) {
        //     // create or drop index --------------------------------------------------
        //     $model = $form->model();

        //     // redirect workflow action page
        //     if (!$this->exists) {
        //         $workflow_action_url = admin_urls('workflow', $model->id, 'action');
    
        //         admin_toastr(exmtrans('workflow.help.saved_redirect_column'));
        //         return redirect($workflow_action_url);
        //     }
        // });

        return $form;
    }

    /**
     * validate before delete.
     */
    protected function validateDestroy($id)
    {
        // check select_table
        $column_count = CustomColumn::whereIn('options->select_target_table', [strval($id), intval($id)])
            ->where('custom_table_id', '<>', $id)
            ->count();

        if ($column_count > 0) {
            return [
                'status'  => false,
                'message' => exmtrans('custom_value.help.reference_error'),
            ];
        }
    }
}
