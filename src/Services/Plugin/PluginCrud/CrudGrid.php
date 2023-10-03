<?php

namespace Exceedone\Exment\Services\Plugin\PluginCrud;

use Encore\Admin\Widgets\Grid\Grid;
use Illuminate\Http\Request;
use Exceedone\Exment\Services\DataImportExport;
use Exceedone\Exment\Form\Tools;

/**
 * Grid for Plugin CRUD(and List)
 */
class CrudGrid extends CrudBase
{
    /**
     * Index. for grid.
     *
     * @return mixed
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

        $grid = new Grid(function ($grid) {
            $this->setGridColumn($grid);
        }, function ($grid, ?array $options) {
            if (is_nullorempty($options)) {
                $options = [];
            }

            if (!is_nullorempty(request()->get('per_page'))) {
                $options['per_page'] = request()->get('per_page');
            }
            if (!is_nullorempty(request()->get('query'))) {
                $options['query'] = request()->get('query');
            }

            if (!is_nullorempty(request()->get('page'))) {
                $options['page'] = request()->get('page');
            }
            if (!isset($options['page'])) {
                $options['page'] = 1;
            }

            // If support paginate, call as paginate values
            if ($this->pluginClass->enablePaginate()) {
                $paginate = $this->pluginClass->getPaginate($options);
                return $paginate;
            }

            // get all values
            return $this->pluginClass->getList($options);
        });

        $grid->setResource($this->getFullUrl())
            ->setChunkCount($this->pluginClass->getChunkCount() ?? 1000);
        $this->setGridTools($grid);
        $this->setGridActions($grid);

        // get primary key
        $primary = $this->pluginClass->getPrimaryKey();
        if (!is_nullorempty($primary)) {
            $grid->setKeyName($primary);
        }

        if (!$this->pluginClass->enablePaginate()) {
            $grid->disablePaginator();
        }

        $this->pluginClass->callbackGrid($grid);

        // create exporter
        $service = $this->getImportExportService($grid);
        $grid->exporter($service);

        return $grid;
    }


    /**
     * Set grid column definition.
     *
     * @param Grid $grid
     * @return void
     */
    protected function setGridColumn(Grid $grid)
    {
        $definitions = $this->pluginClass->getFieldDefinitions();
        // create table
        $targets = collect($definitions)
            ->filter(function ($d) {
                return array_has($d, 'grid');
            })->sortBy('grid');

        foreach ($targets as $target) {
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

        if ($this->pluginClass->enableFreewordSearch()) {
            $grid->quickSearch(function ($model, $input) {
            }, 'left');
        }

        $plugin = $this->plugin;
        $pluginClass = $this->pluginClass;
        $grid->tools(function ($tools) use ($grid, $plugin, $pluginClass) {
            if (!$this->pluginClass->enableDeleteAll()) {
                $tools->batch(function ($batch) {
                    $batch->disableDelete();
                });
            }

            if ($this->pluginClass->enableCreate()) {
                $tools->prepend(view('exment::tools.button', [
                    'href' => admin_url($this->getFullUrl('create')),
                    'label' => trans('admin.new'),
                    'icon' => 'fa-plus',
                    'btn_class' => 'btn-success',
                ])->render(), 'right');
            }

            if ($pluginClass->enableExport()) {
                $button = new Tools\ExportImportButton($plugin->getFullUrl(), $grid, false, true, false);
                $button->setBaseKey('common');

                $tools->prepend($button, 'right');
            }

            // get oauth logout view
            $oauthLogoutView = $this->getOAuthLogoutView();
            if ($oauthLogoutView) {
                $tools->prepend($oauthLogoutView, 'right');
            }

            $pluginClass->callbackGridTool($tools);
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
        $grid->actions(function ($actions) use ($pluginClass) {
            if (!$pluginClass->enableEditAll() || !$pluginClass->enableEdit($actions->row)) {
                $actions->disableEdit();
            }
            if (!$pluginClass->enableDeleteAll() || !$pluginClass->enableDelete($actions->row)) {
                $actions->disableDelete();
            }
            if (!$pluginClass->enableShow($actions->row)) {
                $actions->disableView();
            }

            $pluginClass->callbackGridAction($actions);
        });
    }


    // create import and exporter
    protected function getImportExportService(Grid $grid)
    {
        $service = (new DataImportExport\DataImportExportWidgetService())
            ->exportAction(new DataImportExport\Actions\Export\PaginateAction(
                [
                    'isAll' => request()->get('_export_') == 'all',
                    'grid' => $grid,
                    'headers' => $this->pluginClass->getFieldDefinitions(),
                    'filename' => date('YmdHis'),
                ]
            ))
        ;
        return $service;
    }
}
