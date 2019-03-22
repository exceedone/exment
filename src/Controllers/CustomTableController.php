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
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\Permission;

class CustomTableController extends AdminControllerBase
{
    use HasResourceActions, RoleForm;

    protected $exists = false;

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
        if(!\Exment::user()->hasPermission(Permission::SYSTEM)){
            $grid->disableCreateButton();
        }

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
        $form->text('table_view_name', exmtrans("custom_table.table_view_name"))
            ->required()
            ->help(exmtrans('common.help.view_name'));
        $form->textarea('description', exmtrans("custom_table.field_description"))->rows(3);
        
        $form->header(exmtrans('common.detail_setting'))->hr();
        $form->embeds('options', exmtrans("custom_column.options.header"), function ($form) use ($id) {
            $form->color('color', exmtrans("custom_table.color"))->help(exmtrans("custom_table.help.color"));
            $form->icon('icon', exmtrans("custom_table.icon"))->help(exmtrans("custom_table.help.icon"));
            $form->switchbool('search_enabled', exmtrans("custom_table.search_enabled"))->help(exmtrans("custom_table.help.search_enabled"))->default("1")
            ;
            $form->switchbool('one_record_flg', exmtrans("custom_table.one_record_flg"))
                ->help(exmtrans("custom_table.help.one_record_flg"))
                ->default("0")
                ;

            $form->switchbool('attachment_flg', exmtrans("custom_table.attachment_flg"))->help(exmtrans("custom_table.help.attachment_flg"))
                ->default("1")
                ;
            
            $form->switchbool('revision_flg', exmtrans("custom_table.revision_flg"))->help(exmtrans("custom_table.help.revision_flg"))
                ->default("1")
                ->attribute(['data-filtertrigger' =>true])
                ;
            $form->number('revision_count', exmtrans("custom_table.revision_count"))->help(exmtrans("custom_table.help.revision_count"))
                ->min(0)
                ->max(500)
                ->default(config('exment.revision_count', 100))
                ->attribute(['data-filter' => json_encode(['key' => 'options_revision_flg', 'value' => "1"])])
                ;
                
            $form->switchbool('all_user_editable_flg', exmtrans("custom_table.all_user_editable_flg"))->help(exmtrans("custom_table.help.all_user_editable_flg"))
                ->default("0")
            ;
            
            $form->switchbool('all_user_viewable_flg', exmtrans("custom_table.all_user_viewable_flg"))->help(exmtrans("custom_table.help.all_user_viewable_flg"))
                ->default("0")
            ;
            
            $form->switchbool('all_user_accessable_flg', exmtrans("custom_table.all_user_accessable_flg"))->help(exmtrans("custom_table.help.all_user_accessable_flg"))
                ->default("0")
            ;
        })->disableHeader();

        // if create table, show menulist
        if(!isset($id)){
            $form->switchbool('add_parent_menu_flg', exmtrans("custom_table.add_parent_menu_flg"))->help(exmtrans("custom_table.help.add_parent_menu_flg"))
                ->default("0")
                ->attribute(['data-filtertrigger' =>true])
            ;
            $form->select('add_parent_menu', exmtrans("custom_table.add_parent_menu"))->help(exmtrans("custom_table.help.add_parent_menu"))
            ->options(function($value){
                $options = Menu::selectOptions();
                return $options;
            })
            ->attribute(['data-filter' => json_encode(['key' => 'add_parent_menu_flg', 'value' => '1'])]);
            ;
            $form->ignore('add_parent_menu');
            $form->ignore('add_parent_menu_flg');
        }

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
        
        $form->saving(function (Form $form) {
            $this->exists = $form->model()->exists;
        });

        $form->saved(function (Form $form) use($id) {
            // create or drop index --------------------------------------------------
            $model = $form->model();
            $model->createTable();

            // if has value 'add_parent_menu', add menu
            $this->addMenuAfterSaved($model);


            // redirect custom column page
            if(!$this->exists){
                $table_name = CustomTable::getEloquent($model->id)->table_name;
                $custom_column_url = admin_urls('column', $table_name);
    
                admin_toastr(exmtrans('custom_table.help.saved_redirect_column'));
                return redirect($custom_column_url);    
            }
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
        if (!$this->validateTable($id, Permission::CUSTOM_TABLE)) {
            return;
        }
        return parent::edit($request, $id, $content);
    }

    /**
     * add menu after saved
     */
    protected function addMenuAfterSaved($model){
        // if has value 'add_parent_menu', add menu
        if (!app('request')->has('add_parent_menu_flg') || !app('request')->has('add_parent_menu')) {
            return;
        }
        
        $add_parent_menu_flg = app('request')->input('add_parent_menu_flg');
        if(!boolval($add_parent_menu_flg)){
            return;
        }

        $add_parent_menu = app('request')->input('add_parent_menu');
        if(!isset($add_parent_menu)){
            return;
        }

        // get order
        $order = Menu::where('parent_id', $add_parent_menu)->max('order');
        if(!isset($order)){
            $order = 0;
        }
        $order++;

        // insert
        Menu::insert([
            'parent_id' => $add_parent_menu,
            'order' => $order,
            'title' => $model->table_view_name,
            'icon' => $model->getOption('icon'),
            'uri' => $model->table_name,
            'menu_type' => MenuType::TABLE,
            'menu_name' => $model->table_name,
            'menu_target' => $model->id,
        ]);
    }
}
