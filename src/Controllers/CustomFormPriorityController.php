<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
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
        $this->setPageInfo(exmtrans("custom_form_priority.header"), exmtrans("custom_form_priority.header"), exmtrans("custom_form_priority.description"), 'fa-keyboard-o');
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        $form = new Form(new CustomFormPriority);
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
            'linkage' => json_encode(['condition_key' => admin_urls('webapi', $custom_table->table_name, 'filter-condition')]),
            'targetOptions' => $custom_table->getColumnsSelectOptions([
                'include_condition' => true,
                'include_system' => false,
                'ignore_attachment' => true,
            ]),
            'custom_table' => $custom_table,
            'filterKind' => FilterKind::FORM,
        ]);

        $hasManyTable->callbackField(function ($field) {
            $field->disableHeader();
        });

        $hasManyTable->render();

        $form->tools(function (Form\Tools $tools) use ($custom_table) {
            $tools->add((new Tools\CustomTableMenuButton('form', $custom_table))->render());

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
