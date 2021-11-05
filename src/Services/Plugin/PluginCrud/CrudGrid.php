<?php
namespace Exceedone\Exment\Services\Plugin\PluginCrud;

use Encore\Admin\Widgets\Form;
use Encore\Admin\Widgets\Grid\Grid;
use Encore\Admin\Widgets\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Http\Controllers\Controller;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomFormPriority;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums\FormLabelType;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ShowGridType;
use Exceedone\Exment\Services\FormSetting;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Grid for Plugin CRUD(and List)
 */
class CrudGrid
{
    public function __construct($plugin, $pluginClass, $options = [])
    {
        $this->plugin = $plugin;
        $this->pluginClass = $pluginClass;
    }

    protected $plugin;
    protected $pluginClass;
    
    /**
     * Index. for grid.
     *
     * @param Request $request
     * @return void
     */
    public function index()
    {
        $content = $this->pluginClass->getContent();
        
        $content->body($this->grid()->render());

        return $content;
    }

    
    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $definitions = $this->pluginClass->getFieldDefinitions();

        $grid = new Grid(function($grid){
            $this->setGridColumn($grid);
        });

        $prms = [
            'per_page' => request()->get($grid->getPerPageName()),
        ];
        if(!is_nullorempty(request()->get('query'))){
            $prms['query'] = request()->get('query');
        }

        $paginate = $this->pluginClass->getPaginate($prms);

        // get primary key
        $primary = $this->pluginClass->getPrimaryKey();

        $grid->setPaginator($paginate)
            ->setResource($this->plugin->getFullUrl());
        
        $this->setGridTools($grid);
        $this->setGridActions($grid);

        if(!is_nullorempty($primary)){
            $grid->setKeyName($primary);
        }

        $this->pluginClass->callbackGrid($grid);

        return $grid;
    }


    /**
     * Set grid column definition.
     *
     * @param Grid $grid
     * @return void
     */
    protected function setGridColumn(Grid $grid){
        $definitions = $this->pluginClass->getFieldDefinitions();
        // create table
        $targets = collect($definitions)
            ->filter(function($d){
                return array_has($d, 'grid');
            })->sortBy('grid');

        foreach($targets as $target){
            $this->pluginClass->setGridColumnDifinition($grid, array_get($target, 'key'), array_get($target, 'label'));
        }
    }

    /**
     * Set grid tools.
     *
     * @param Grid $grid
     * @return void
     */
    protected function setGridTools(Grid $grid)
    {
        $grid->disableCreateButton();

        if($this->pluginClass->enableFreewordSearch()){
            $grid->quickSearch(function ($model, $input) {
            }, 'left');
        }
        
        $plugin = $this->plugin;
        $pluginClass = $this->pluginClass;
        $grid->tools(function($tools) use($grid, $plugin, $pluginClass){
            if($this->pluginClass->enableCreate()){
                $tools->prepend(view('exment::tools.button', [
                    'href' => admin_url($this->plugin->getFullUrl('create')),
                    'label' => trans('admin.new'),
                    'icon' => 'fa-plus',
                    'btn_class' => 'btn-success',
                ])->render(), 'right');
            }
            
            if($pluginClass->enableExport()){
                $button = new Tools\ExportImportButton($plugin->getFullUrl(), $grid, false, true, false);
                $button->setBaseKey('common');
                
                $tools->prepend($button, 'right');
            }
        });
    }

    /**
     * Set grid actions.
     *
     * @param Grid $grid
     * @return void
     */
    protected function setGridActions(Grid $grid)
    {
        $pluginClass = $this->pluginClass;
        $grid->actions(function($actions) use($pluginClass){
            if(!$pluginClass->enableEdit() || !$pluginClass->enableEditData($actions->row)){
                $actions->disableEdit();
            }
            if(!$pluginClass->enableDelete() || !$pluginClass->enableDeleteData($actions->row)){
                $actions->disableDelete();
            }
        });
    }
}
