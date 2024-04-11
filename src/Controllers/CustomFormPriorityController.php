<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Enums\FilterKind;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\CustomFormPriority;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Http\Request;

/**
 * Custom Form Controller
 */
class CustomFormPriorityController extends AdminControllerTableBase
{
    use HasResourceTableActions;

    public function __construct(?CustomTable $custom_table, Request $request)
    {
        parent::__construct($custom_table, $request);

        $title = exmtrans("custom_form_priority.header") . ' : ' . ($custom_table ? $custom_table->table_view_name : null);
        $this->setPageInfo($title, $title, exmtrans("custom_form_priority.description"), 'fa-keyboard-o');
    }

    /**
     * @param Request $request
     * @param Content $content
     * @return Content|\Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function index(Request $request, Content $content)
    {
        return redirect(admin_urls('form', $this->custom_table->table_name));
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        $form = new Form(new CustomFormPriority());
        $custom_table = $this->custom_table;
        $form->select('custom_form_id', exmtrans("custom_form_priority.custom_form_id"))->required()
            ->options(function ($value) use ($custom_table) {
                return $custom_table->custom_forms->mapWithKeys(function ($item) {
                    return [$item['id'] => $item['form_view_name']];
                });
            });
        $form->number('order', exmtrans("custom_form_priority.order"))->rules("integer")
            ->help(exmtrans("custom_form_priority.help.order"));

        // filter setting
        $hasManyTable = new Tools\ConditionHasManyTable($form, [
            'ajax' => admin_urls('webapi', $custom_table->table_name, 'filter-value'),
            'name' => 'custom_form_priority_conditions',
            'linkage' => json_encode(['condition_key' => url_join($custom_table->table_name, 'filter-condition')]),
            'targetOptions' => $custom_table->getColumnsSelectOptions([
                'include_condition' => true,
                'include_system' => false,
                'ignore_attachment' => true,
                'include_form_type' => true,
                'include_workflow' => true,
                'include_workflow_work_users' => true,
            ]),
            'custom_table' => $custom_table,
            'filterKind' => FilterKind::FORM,
        ]);

        $hasManyTable->callbackField(function ($field) {
            $field->disableHeader();
        });

        $hasManyTable->render();

        $form->radio('condition_join', exmtrans("condition.condition_join"))
            ->options(exmtrans("condition.condition_join_options"))
            ->default('and');

        $form->checkboxone('condition_reverse', exmtrans("condition.condition_reverse"))
            ->option(exmtrans("condition.condition_reverse_options"));

        $form->tools(function (Form\Tools $tools) use ($custom_table) {
            $tools->add(new Tools\CustomTableMenuButton('form', $custom_table));
            $tools->setListPath(admin_urls('form', $custom_table->table_name));
        });

        $table_name = $this->custom_table->table_name;

        $form->saved(function ($form) use ($table_name) {
            admin_toastr(trans('admin.update_succeeded'));
            return redirect(admin_url("form/$table_name"));
        });

        return $form;
    }
}
