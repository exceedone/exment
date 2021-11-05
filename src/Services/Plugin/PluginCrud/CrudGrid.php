<?php
namespace Exceedone\Exment\Services\Plugin\PluginCrud;

use Encore\Admin\Widgets\Form;
use Encore\Admin\Widgets\Grid\Grid;
use Illuminate\Http\Request;
use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Form\Tools;

/**
 * Grid for Plugin CRUD(and List)
 */
class CrudGrid extends CrudBase
{
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
            ->setResource($this->getFullUrl());
        
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
                    'href' => admin_url($this->getFullUrl('create')),
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
