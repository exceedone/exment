<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Auth\Permission as Checker;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Form\Widgets\ProgressTracker;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Services\NotifyService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class WorkflowNotifyController extends Controller
{
    use HasResourceActions{
        destroy as destroyTrait;
    }
    use NotifyTrait;
    use WorkflowTrait;
    use ExmentControllerTrait;

    protected $workflow;

    public function __construct()
    {
        $this->setPageInfo(exmtrans("notify.header"), exmtrans("notify.header"), exmtrans("notify.description"), 'fa-bell');
    }


    /**
     * Execute an action on the controller.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function callAction($method, $parameters)
    {
        $this->workflow = Workflow::getEloquent(array_get($parameters, 'workflow_id'));
        if (!$this->workflow) {
            abort(404);
        }
        return parent::callAction($method, $parameters);
    }


    /**
     * Create interface.
     *
     * @return Content
     */
    public function create(Request $request, Content $content)
    {
        if (!is_null($copy_id = $request->get('copy_id'))) {
            return $this->AdminContent($content)->body($this->form(null, $copy_id)->replicate($copy_id, ['notify_view_name']));
        }

        return $this->AdminContent($content)->body($this->form());
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new Notify());

        $grid->header(function ($grid) {
            $process = new ProgressTracker();
            return $process->options($this->getProgressInfo($this->workflow, 3))
                ->render();
        });

        $grid->column('target_id', exmtrans("notify.notify_target"))->sortable()->display(function ($val, $column, $model) {
            $workflow = Workflow::getEloquent($model->target_id);
            if (isset($workflow)) {
                return $workflow->workflow_view_name ?? null;
            }
            return null;
        });

        $this->setBasicGrid($grid);

        $grid->column('action_settings', exmtrans("notify.notify_action"))->sortable()->display(function ($val) {
            return collect($val)->map(function ($v) {
                $enum = NotifyAction::getEnum(array_get($v, 'notify_action'));
                return isset($enum) ? $enum->transKey('notify.notify_action_options') : null;
            })->filter()->unique()->implode(exmtrans('common.separate_word'));
        });

        $grid->column('active_flg', exmtrans("plugin.active_flg"))->sortable()->display(function ($val) {
            return \Exment::getTrueMark($val);
        })->escape(false);

        $grid->tools(function (Grid\Tools $tools) {
            $tools->prepend(new Tools\SystemChangePageMenu());
            $tools->prepend(view('exment::tools.button', [
                'href' => admin_urls('workflow'),
                'label' => exmtrans('workflow.header') . trans('admin.list'),
                'icon' => 'fa-list',
                'btn_class' => 'btn-default',
            ]));
        });

        $grid->model()->where('target_id', $this->workflow->id)
            ->where('notify_trigger', NotifyTrigger::WORKFLOW);

        $workflow = $this->workflow;
        $grid->actions(function (Grid\Displayers\Actions $actions) use ($workflow) {
            $actions->disableView();

            $linker = (new Linker())
                ->url(admin_urls("workflow", $workflow->id, "notify", "create?copy_id={$actions->row->id}"))
                ->icon('fa-copy')
                ->tooltip(exmtrans('common.copy_item', exmtrans('notify.notify')));
            $actions->prepend($linker);
        });

        $this->setFilterGrid($grid);

        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @param $id
     * @param $copy_id
     * @return Form|false|void
     */
    protected function form($id = null, $copy_id = null)
    {
        if (!$this->hasPermissionEdit($id)) {
            return;
        }

        $form = new Form(new Notify());
        $form->progressTracker()->options($this->getProgressInfo($this->workflow, 3));

        $notify = Notify::find($id);
        if ($notify && $notify->notify_trigger != NotifyTrigger::WORKFLOW) {
            Checker::error(exmtrans('common.message.wrongdata'));
            return false;
        }

        $workflow = $this->workflow;

        $form->internal('target_id')->default($this->workflow->id);
        $form->display('workflow_view_name', exmtrans("workflow.workflow_view_name"))
            ->default($this->workflow->workflow_view_name);

        $this->setBasicForm($form, $notify);

        $form->exmheader(exmtrans('notify.header_trigger'))->hr();

        $form->internal('notify_trigger')->default(NotifyTrigger::WORKFLOW);
        $form->display('notify_trigger', exmtrans("notify.notify_trigger"))
            ->displayText(exmtrans("notify.notify_trigger_options.workflow"));

        $form->embeds('trigger_settings', exmtrans("notify.trigger_settings"), function (Form\EmbeddedForm $form) use ($workflow) {
            $form->switchbool('notify_myself', exmtrans("notify.notify_myself"))
                ->default(false)
                ->help(exmtrans("notify.help.notify_myself"));
            $form->multipleSelect('filter_status_to', exmtrans('notify.filter_status_to'))
                ->options($workflow->getStatusOptions())
                ->help(exmtrans('notify.help.filter_status_to'));
            $form->multipleSelect('filter_actions', exmtrans('notify.filter_actions'))
                ->options($workflow->getActionOptions())
                ->help(exmtrans('notify.help.filter_actions'));
        })->disableHeader();

        $form->exmheader(exmtrans("notify.header_action"))->hr();

        $form->hasManyJson('action_settings', exmtrans("notify.action_settings"), function ($form) use ($notify) {
            $form->select('notify_action', exmtrans("notify.notify_action"))
                ->options(NotifyAction::transKeyArray("notify.notify_action_options"))
                ->required()
                ->disableClear()
                ->attribute([
                    'data-filtertrigger' =>true,
                    'data-linkage' => json_encode([
                        'notify_action_target' => admin_urls('workflow', $this->workflow->id, 'notify', 'notify_action_target'),
                    ]),
                ])
                ->help(exmtrans("notify.help.notify_action"))
            ;

            $this->setActionForm($form, $notify, null, $this->workflow);
        })->required()->disableHeader();

        $mail_template = CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE)
            ->getValueModel()
            ->where('value->mail_key_name', MailKeyName::WORKFLOW_NOTIFY)
            ->first();

        $this->setMailTemplateForm($form, $notify, $mail_template ? $mail_template->id : null);

        $this->setFooterForm($form, $notify);

        $form->tools(function (Form\Tools $tools) use ($workflow) {
            $tools->disableList();

            $tools->append(new Tools\SystemChangePageMenu());

            $tools->append(view('exment::tools.button', [
                'href' => admin_urls('workflow', $workflow->id, 'notify'),
                'label' => exmtrans('notify.header') . trans('admin.list'),
                'icon' => 'fa-list',
                'btn_class' => 'btn-default',
            ]));

            $tools->append(view('exment::tools.button', [
                'href' => admin_urls('workflow'),
                'label' => exmtrans('workflow.header') . trans('admin.list'),
                'icon' => 'fa-list',
                'btn_class' => 'btn-default',
            ]));
        });

        return $form;
    }


    public function notify_action_target(Request $request, $workflow_id)
    {
        $options = NotifyService::getNotifyTargetColumns(null, $request->get('q'), [
            'as_workflow' => true,
            'workflow' => Workflow::find($workflow_id)
        ]);

        return $options;
    }


    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        return $this->AdminContent($content)->body($this->grid());
    }

    /**
     * Show interface.
     *
     * @param Request $request
     * @param Content $content
     * @param $workflow_id
     * @param $id
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function show(Request $request, Content $content, $workflow_id, $id)
    {
        if (method_exists($this, 'detail')) {
            $render = $this->detail($id);
        } else {
            $url = url_join($request->url(), 'edit');
            return redirect($url);
        }
        return $this->AdminContent($content)->body($render);
    }

    /**
     * Edit interface.
     *
     * @param mixed   $id
     * @param Content $content
     * @return Content
     */
    public function edit(Request $request, Content $content, $workflow_id, $id)
    {
        return $this->AdminContent($content)->body($this->form($id)->edit($id));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update($workflow_id, $id)
    {
        return $this->form($id)->update($id);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($workflow_id, $id)
    {
        return $this->destroyTrait($id);
    }
}
