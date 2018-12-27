<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Controllers\HasResourceActions;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Role;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\RoleValue;

class CustomTableController extends AdminControllerBase
{
    use HasResourceActions, RoleForm;

    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("custom_table.header"), exmtrans("custom_table.header"), exmtrans("custom_table.description"));
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new CustomTable);
        $grid->column('table_name', exmtrans("custom_table.table_name"))->sortable();
        $grid->column('table_view_name', exmtrans("custom_table.table_view_name"))->sortable();
        
        $grid->tools(function (Grid\Tools $tools) {
            $tools->append(new Tools\GridChangePageMenu('table', null, true));
        });

        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            if (boolval($actions->row->system_flg)) {
                $actions->disableDelete();
            }
            $actions->disableView();
        });

        // filter table --------------------------------------------------
        CustomTable::filterList($grid->model(), ['getModel' => false]);

        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        $form = new Form(new CustomTable);
        if (!isset($id)) {
            $form->text('table_name', exmtrans("custom_table.table_name"))
                ->required()
                ->rules("unique:".CustomTable::getTableName()."|regex:/".Define::RULES_REGEX_SYSTEM_NAME."/")
                ->help(exmtrans('common.help_code'));
        } else {
            $form->display('table_name', exmtrans("custom_table.table_name"));
        }
        $form->text('table_view_name', exmtrans("custom_table.table_view_name"))->required();
        $form->textarea('description', exmtrans("custom_table.field_description"))->rows(3);
        $form->switchbool('search_enabled', exmtrans("custom_table.search_enabled"))->help(exmtrans("custom_table.help.search_enabled"))->default("1");
        
        $form->header(exmtrans('common.detail_setting'))->hr();
        $form->embeds('options', exmtrans("custom_column.options.header"), [function ($form) use ($id) {
            $form->color('color', exmtrans("custom_table.color"))->help(exmtrans("custom_table.help.color"))->setWidth(9, 3);
            $form->icon('icon', exmtrans("custom_table.icon"))->help(exmtrans("custom_table.help.icon"))->setWidth(9, 3);
            $form->switchbool('one_record_flg', exmtrans("custom_table.one_record_flg"))
                ->help(exmtrans("custom_table.help.one_record_flg"))
                ->default("0")
                ->setWidth(9, 3);
            $form->switchbool('attachment_flg', exmtrans("custom_table.attachment_flg"))->help(exmtrans("custom_table.help.attachment_flg"))
                ->default("1")
                ->setWidth(9, 3);
        }, function ($form) use ($id) {
            $form->switchbool('revision_flg', exmtrans("custom_table.revision_flg"))->help(exmtrans("custom_table.help.revision_flg"))
                ->default("1")
                ->attribute(['data-filtertrigger' =>true])
                ->setWidth(9, 3)
                ;
            $form->number('revision_count', exmtrans("custom_table.revision_count"))->help(exmtrans("custom_table.help.revision_count"))
                ->min(0)
                ->max(500)
                ->default(config('exment.revision_count', 100))
                ->attribute(['data-filter' => json_encode(['key' => 'options_revision_flg', 'value' => "1"])])
                ->setWidth(9, 3)
                ;
                
            $form->switchbool('all_user_editable_flg', exmtrans("custom_table.all_user_editable_flg"))->help(exmtrans("custom_table.help.all_user_editable_flg"))
                ->default("0")
                ->setWidth(9, 3)
            ;
            
            $form->switchbool('all_user_viewable_flg', exmtrans("custom_table.all_user_viewable_flg"))->help(exmtrans("custom_table.help.all_user_viewable_flg"))
                ->default("0")
                ->setWidth(9, 3)
            ;
            
            $form->switchbool('all_user_accessable_flg', exmtrans("custom_table.all_user_accessable_flg"))->help(exmtrans("custom_table.help.all_user_accessable_flg"))
                ->default("0")
                ->setWidth(9, 3)
            ;
            // $form->switchbool('notify_flg', exmtrans("custom_table.notify_flg"))->help(exmtrans("custom_table.help.notify_flg"))
            //     ->default("0")
            //     ->setWidth(9, 3)
            //     ;
        }])->disableHeader();

        // Role setting --------------------------------------------------
        $this->addRoleForm($form, RoleType::TABLE);
        
        disableFormFooter($form);
        $form->tools(function (Form\Tools $tools) use ($id, $form) {
            $tools->disableView();
            // if edit mode
            if ($id != null) {
                $model = CustomTable::findOrFail($id);
                $tools->add((new Tools\GridChangePageMenu('table', $model, false))->render());
            }
        });
        
        $form->saved(function (Form $form) {
            // create or drop index --------------------------------------------------
            $model = $form->model();
            $model->createTable();
        });

        return $form;
    }
    
    /**
     * Edit interface.
     *
     * @param mixed   $id
     * @param Content $content
     * @return Content
     */
    public function edit(Request $request, $id, Content $content)
    {
        if (!$this->validateTable($id, RoleValue::CUSTOM_TABLE)) {
            return;
        }
        return parent::edit($request, $id, $content);
    }
}
