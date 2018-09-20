<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Controllers\ModelForm;
//use Encore\Admin\Widgets\Form;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Authority;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Form\Tools;

class CustomTableController extends AdminControllerBase
{
    use ModelForm, AuthorityForm;

    public function __construct(Request $request){
        $this->setPageInfo(exmtrans("custom_table.header"), exmtrans("custom_table.header"), exmtrans("custom_table.description"));
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Admin::grid(CustomTable::class, function (Grid $grid) {

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
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        return Admin::form(CustomTable::class, function (Form $form) use($id){
            if(!isset($id)){
                $form->text('table_name', exmtrans("custom_table.table_name"))->rules("required|unique:".CustomTable::getTableName()."|regex:/".Define::RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN."/")
                    ->help(exmtrans('common.help_code'));
            }else{
                $form->display('table_name', exmtrans("custom_table.table_name"));
            }
            $form->text('table_view_name', exmtrans("custom_table.table_view_name"))->rules("required");
            $form->textarea('description', exmtrans("custom_table.field_description"))->rows(3);
            $form->color('color', exmtrans("custom_table.color"))->help(exmtrans("custom_table.help.color"));
            $form->icon('icon', exmtrans("custom_table.icon"))->help(exmtrans("custom_table.help.icon"));
            $form->switchbool('search_enabled', exmtrans("custom_table.search_enabled"))->help(exmtrans("custom_table.help.search_enabled"))->default("1");
            $form->switchbool('one_record_flg', exmtrans("custom_table.one_record_flg"))->help(exmtrans("custom_table.help.one_record_flg"));

            // Authority setting --------------------------------------------------
            $this->addAuthorityForm($form, Define::AUTHORITY_TYPE_TABLE);

            $form->disableReset();
            $form->disableViewCheck();
            $form->tools(function (Form\Tools $tools) use($id, $form) {
                $tools->disableView();
                // if edit mode
                if($id != null){
                    $model = CustomTable::findOrFail($id);
                    $tools->add((new Tools\GridChangePageMenu('table', $model, false))->render());
                }
            });
        });
    }
}
