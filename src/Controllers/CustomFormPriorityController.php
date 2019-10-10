<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Form\Field\ChangeField;
use Exceedone\Exment\Model\CustomFormPriority;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Custom Form Controller
 */
class CustomFormPriorityController extends AdminControllerTableBase
{
    use HasResourceTableActions;

    public function __construct(CustomTable $custom_table, Request $request)
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
        $form->hasManyTable('custom_form_priority_conditions', exmtrans("custom_form_priority.custom_form_priority_conditions"), function ($form) use ($custom_table) {
            $form->select('form_priority_target', exmtrans("custom_form_priority.form_priority_target"))->required()
                ->options($custom_table->getPrioritySelectOptions());

            $label = exmtrans('custom_form_priority.form_filter_condition_value');
            $form->changeField('form_filter_condition_value', $label)
                ->required()
                ->rules("changeFieldValue:$label");
        })->setTableColumnWidth(4, 7, 1)
        ->description(exmtrans('custom_form_priority.help.custom_form_priority_conditions'));

        $form->tools(function (Form\Tools $tools) use($custom_table) {
            $tools->add((new Tools\GridChangePageMenu('form', $custom_table, false))->render());

            $tools->setListPath(admin_urls('form', $custom_table->table_name));
        });

        $table_name = $this->custom_table->table_name;

        $form->saved(function ($form) use($table_name) {
            admin_toastr(trans('admin.update_succeeded'));
            return redirect(admin_url("form/$table_name"));
        });

        $script = <<<EOT
            $('#has-many-table-custom_form_priority_conditions').off('change').on('change', '.form_priority_target', function (ev) {
                $.ajax({
                    url: admin_url("formpriority/$table_name/filter-value"),
                    type: "GET",
                    data: {
                        'target_name': $(this).attr('name'),
                        'target_val': $(this).val(),
                    },
                    context: this,
                    success: function (data) {
                        var json = JSON.parse(data);
                        $(this).closest('tr.has-many-table-custom_form_priority_conditions-row').find('td:nth-child(2)>div>div').html(json.html);
                        if (json.script) {
                            eval(json.script);
                        }
                    },
                });
            });
EOT;
        Admin::script($script);
        return $form;
    }

    /**
     * get filter condition
     */
    public function getFilterValue(Request $request)
    {
        $data = $request->all();

        if (!array_key_exists('target_val', $data) ||
            !array_key_exists('target_name', $data)) {
            return [];
        }

        $columnname = 'form_filter_condition_value';
        $label = exmtrans('custom_form_priority.'.$columnname);

        $field = new ChangeField($columnname, $label);
        $field->data([
            'form_priority_target' => $data['target_val'],
        ])->rules("changeFieldValue:$label");
        $element_name = str_replace('form_priority_target', 'form_filter_condition_value', $data['target_name']);
        $field->setElementName($element_name);

        $view = $field->render();
        return \json_encode(['html' => $view->render(), 'script' => $field->getScript()]);
    }
}
