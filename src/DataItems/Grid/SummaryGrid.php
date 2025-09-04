<?php

namespace Exceedone\Exment\DataItems\Grid;

use Encore\Admin\Grid;
use Encore\Admin\Form;
use Exceedone\Exment\ColumnItems;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Services\DataImportExport;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\GroupCondition;
use Illuminate\Support\Collection;

class SummaryGrid extends GridBase
{
    public function __construct($custom_table, $custom_view)
    {
        $this->custom_table = $custom_table;
        $this->custom_view = $custom_view;
    }

    public function grid()
    {
        $classname = getModelName($this->custom_table);
        $grid = new Grid(new $classname());

        $this->setSummaryGrid($grid);

        $this->setCustomGridFilters($grid);

        $this->setGrid($grid);

        $grid_per_pages = stringToArray(config('exment.grid_per_pages'));
        if (empty($grid_per_pages)) {
            $grid_per_pages = Define::PAGER_GRID_COUNTS;
        }
        $grid->perPages($grid_per_pages);

        $grid->disableCreateButton();
        // $grid->disableFilter();
        //$grid->disableActions();
        $grid->disableRowSelector();
        $grid->disableExport();

        $table_name = $this->custom_table->table_name;
        $custom_view = $this->custom_view;
        $isShowViewSummaryDetail = $this->isShowViewSummaryDetail();
        if (!$isShowViewSummaryDetail) {
            $grid->disableActions();
        }
        $alldata_view = CustomView::getAllData($table_name);

        $_this = $this;
        $grid->actions(function (Grid\Displayers\Actions $actions) use ($_this, $isShowViewSummaryDetail, $custom_view, $table_name, $alldata_view) {
            $actions->disableDelete();
            $actions->disableEdit();
            $actions->disableView();

            if ($isShowViewSummaryDetail) {
                $params = $_this->getCallbackGroupKeys($actions->row);

                $linker = (new Grid\Linker())
                    ->url(admin_urls_query('data', $table_name, ['view' => $alldata_view->suuid, 'group_view' => $custom_view->suuid, 'group_key' => json_encode($params)]))
                    ->icon('fa-list')
                    ->tooltip(exmtrans('custom_value.view_summary_detail'));
                $actions->prepend($linker);
            }
        });

        // create exporter
        $service = (new DataImportExport\DataImportExportService())
            ->exportAction(new DataImportExport\Actions\Export\SummaryAction(
                [
                    'grid' => $grid,
                    'custom_table' => $this->custom_table,
                    'custom_view' => $this->custom_view,
                ]
            ));
        $grid->exporter($service);

        $grid->tools(function (Grid\Tools $tools) use ($grid) {
            // have edit flg
            $edit_flg = $this->custom_table->enableEdit(true) === true;
            if ($edit_flg && $this->custom_table->enableExport() === true) {
                $button = new Tools\ExportImportButton(admin_urls('data', $this->custom_table->table_name), $grid, false, true, false);
                /** @phpstan-ignore-next-line append expects Encore\Admin\Grid\Tools\AbstractTool|string, Exceedone\Exment\Form\Tools\ExportImportButton given */
                $tools->append($button->setCustomTable($this->custom_table));
            }

            // if user have edit permission, add button
            if ($edit_flg) {
                $tools->append(view('exment::custom-value.new-button', ['table_name' => $this->custom_table->table_name]));
            }

            if ($this->custom_table->enableTableMenuButton()) {
                /** @phpstan-ignore-next-line expects Encore\Admin\Grid\Tools\AbstractTool|string, Exceedone\Exment\Form\Tools\CustomTableMenuButton given */
                $tools->append(new Tools\CustomTableMenuButton('data', $this->custom_table));
            }
            if ($this->custom_table->enableViewMenuButton()) {
                /** @phpstan-ignore-next-line expects Encore\Admin\Grid\Tools\AbstractTool|string, Exceedone\Exment\Form\Tools\CustomViewMenuButton given */
                $tools->append(new Tools\CustomViewMenuButton($this->custom_table, $this->custom_view));
            }
        });

        return $grid;
    }

    /**
     * set summary grid
     */
    public function setSummaryGrid($grid)
    {
        $query = $grid->model();
        return $this->getQuery($query, ['grid' => $grid]);
    }


    /**
     * set laravel-admin grid using custom_view
     */
    public function setGrid($grid)
    {
        // set with
        $this->custom_table->setQueryWith($grid->model(), $this->custom_view);
    }


    /**
     * get query for summary
     */
    public function getQuery($query, array $options = [])
    {
        $options = array_merge([
            'grid' => null,
        ], $options);
        $grid = $options['grid'];

        // get search service.
        $searchSearvice = $this->custom_view->getSearchService()->setQuery($query);

        ///// set "group by" columns, and "select" columns.
        // using custom_view_columns
        foreach ($this->custom_view->custom_view_columns_cache as $custom_view_column) {
            // set option item
            $this->setSummaryItem($custom_view_column);

            // set group by
            $searchSearvice->groupByCustomViewColumn($custom_view_column);

            // set grid column
            $this->setGridColumn($grid, $custom_view_column);
        }


        ///// set summary's "select" columns.
        // using custom_view_summary
        foreach ($this->custom_view->custom_view_summaries_cache as $custom_view_summary) {
            // set option item
            $this->setSummaryItem($custom_view_summary);

            // set select summary
            $searchSearvice->selectSummaryCustomViewSummary($custom_view_summary);

            // set grid column
            $this->setGridColumn($grid, $custom_view_summary);
        }


        ///// set filter columns.
        $this->custom_view->setValueFilters($query);

        // if request not has "_sort", execute Summary OrderBy
        if (!request()->has('_sort')) {
            $searchSearvice->executeSummaryOrderBy();
        }

        // call join children.
        $searchSearvice->executeSummaryJoin();

        return $query;
    }

    /**
     * Set grid column
     *
     * @param Grid|null $grid
     * @param CustomViewColumn|CustomViewSummary $column
     * @return $this
     */
    protected function setGridColumn(?Grid $grid, $column)
    {
        if (is_null($grid)) {
            return $this;
        }

        $column_item = $column->column_item;
        $column_label = $column_item->label();

        $grid_column = $grid->column($column_item->sqlAsName(), $column_label)
            ->sort($column_item->sortable())
            ->display(function ($id, $column, $custom_value) use ($column_item) {
                return $column_item->setCustomValue($custom_value)->html();
            })->escape(false);

        if (!\Exment::isSqlServer() && $column instanceof CustomViewColumn) {
            $grid_column->cast($column_item->getCastName(true));
        }

        return $this;
    }


    /**
     * Set summary item
     *
     * @param CustomViewColumn|CustomViewSummary $column
     * @return $this
     */
    protected function setSummaryItem($column)
    {
        $column_item = $column->column_item;
        $column_item->options([
            'summary' => true,
            'summary_condition' => array_get($column, 'view_summary_condition'),
            'group_condition' => array_get($column, 'view_group_condition'),
        ]);

        // Set label.
        if (!is_nullorempty($view_column_name = array_get($column, 'view_column_name'))) {
            $column_item->setLabel($view_column_name);
        }
        // Set default label if summary
        elseif ($column instanceof CustomViewSummary) {
            $summary_condition = SummaryCondition::getSummaryConditionName(array_get($column, 'view_summary_condition'));
            if (!is_nullorempty($summary_condition)) {
                $column_item->setLabel(exmtrans('common.format_keyvalue', exmtrans("custom_view.summary_condition_options.{$summary_condition}"), $column_item->label()));
            }
        }

        return $this;
    }


    protected function  isShowViewSummaryDetail()
    {
        if (boolval(request()->get('execute_filter'))) {
            return false;
        }
        return !$this->custom_view->custom_view_columns->contains(function ($custom_view_column) {
            return $this->custom_table->id != $custom_view_column->view_column_table_id;
        }) && !$this->custom_view->custom_view_summaries->contains(function ($custom_view_summary) {
            return $this->custom_table->id != $custom_view_summary->view_column_table_id;
        }) && !$this->custom_view->custom_view_filters->contains(function ($custom_view_filter) {
            return $this->custom_table->id != $custom_view_filter->view_column_table_id;
        });
    }


    /**
     * Set custom view columns( group columns )form. For controller.
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @return void
     */
    public static function setViewForm($view_kind_type, $form, $custom_table, array $options = [])
    {
        static::setViewInfoboxFields($form);

        $manualUrl = getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'));

        // group columns setting
        $form->hasManyTable('custom_view_columns', exmtrans("custom_view.custom_view_groups"), function ($form) use ($custom_table) {
            $targetOptions = $custom_table->getColumnsSelectOptions([
                'append_table' => true,
                'index_enabled_only' => true,
                'include_parent' => true,
                'include_child' => true,
                'include_workflow' => true,
                'is_aggregate' => true,
            ]);

            $field = $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                ->options($targetOptions)
                ->attribute([
                    'data-linkage' => json_encode(['view_group_condition' => admin_urls('view', $custom_table->table_name, 'group-condition')]),
                    'data-change_field_target' => 'view_column_target',
                ]);

            if (boolval(config('exment.form_column_option_group', false))) {
                $targetGroups = static::convertGroups($targetOptions, $custom_table);
                $field->groups($targetGroups);
            }

            $form->text('view_column_name', exmtrans("custom_view.view_column_name"))->icon(null);

            $form->select('view_group_condition', exmtrans("custom_view.view_group_condition"))
                // ignore HasOptionRule.
                ->removeRules(\Encore\Admin\Validator\HasOptionRule::class)
                ->options(function ($val, $form) {
                    if (is_null($data = $form->data())) {
                        return [];
                    }
                    if (is_null($view_column_target = array_get($data, 'view_column_target'))) {
                        return [];
                    }
                    return collect(SummaryGrid::getGroupCondition($view_column_target))->pluck('text', 'id')->toArray();
                });

            $form->select('sort_order', exmtrans("custom_view.sort_order"))
                ->options(array_merge([''], range(1, 5)))
                ->help(exmtrans('custom_view.help.sort_order_summaries'));
            $form->select('sort_type', exmtrans("custom_view.sort"))
            ->help(exmtrans('custom_view.help.sort_type'))
                ->options(Enums\ViewColumnSort::transKeyArray('custom_view.column_sort_options'))
                ->disableClear()->default(Enums\ViewColumnSort::ASC);

            $form->hidden('order')->default(0);
        })->required()->rowUpDown('order')->setTableColumnWidth(4, 2, 2, 1, 2, 1)
        ->descriptionHtml(sprintf(exmtrans("custom_view.description_custom_view_groups"), $manualUrl));

        // summary columns setting
        $form->hasManyTable('custom_view_summaries', exmtrans("custom_view.custom_view_summaries"), function ($form) use ($custom_table) {
            $targetOptions = $custom_table->getSummaryColumnsSelectOptions();
            $field = $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                ->options($targetOptions)
                ->attribute(['data-linkage' => json_encode(['view_summary_condition' => admin_urls('view', $custom_table->table_name, 'summary-condition')])]);

            if (boolval(config('exment.form_column_option_group', false))) {
                $targetGroups = static::convertGroups($targetOptions, $custom_table);
                $field->groups($targetGroups);
            }
            $form->select('view_summary_condition', exmtrans("custom_view.view_summary_condition"))
                ->options(function ($val, $form) {
                    $view_column_target = array_get($form->data(), 'view_column_target');
                    if (isset($view_column_target)) {
                        $columnItem = CustomViewColumn::getColumnItem($view_column_target);
                        if (isset($columnItem)) {
                            // only numeric
                            if ($columnItem->isNumeric()) {
                                $options = SummaryCondition::getOptions();
                            } else {
                                $options = SummaryCondition::getOptions(['numeric' => false]);
                            }

                            return array_map(function ($array) {
                                return exmtrans('custom_view.summary_condition_options.'.array_get($array, 'name'));
                            }, $options);
                        }
                    }
                    return [];
                })
                // ignore HasOptionRule.
                ->removeRules(\Encore\Admin\Validator\HasOptionRule::class)
                ->required()->rules('summaryCondition');
            $form->text('view_column_name', exmtrans("custom_view.view_column_name"))->icon(null);
            $form->select('sort_order', exmtrans("custom_view.sort_order"))
                ->help(exmtrans('custom_view.help.sort_order_summaries'))
                ->options(array_merge([''], range(1, 5)));
            $form->select('sort_type', exmtrans("custom_view.sort"))
                ->help(exmtrans('custom_view.help.sort_type'))
                ->options(Enums\ViewColumnSort::transKeyArray('custom_view.column_sort_options'))
                ->disableClear()->default(Enums\ViewColumnSort::ASC);
        })->setTableColumnWidth(4, 2, 2, 1, 2, 1)
        ->descriptionHtml(sprintf(exmtrans("custom_view.description_custom_view_summaries"), $manualUrl));

        // filter setting
        static::setFilterFields($form, $custom_table, true);

        static::setGridFilterFields($form, $custom_table);
    }

    /**
     * Set column gridfilter item form
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @return void
     */
    public static function setGridFilterFields(&$form, $custom_table, array $column_options = [])
    {
        // columns setting
        $column_options = array_merge([
            'append_table' => true,
            'include_parent' => true,
            'include_workflow' => true,
            'index_enabled_only' => true,
            'only_system_grid_filter' => true,
            'ignore_many_to_many' => true,
            'ignore_multiple_refer' => true,
        ], $column_options);

        $manualUrl = getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'));
        $description = exmtrans("custom_view.description_custom_view_grid_filters", $manualUrl);
        $description .= exmtrans("custom_view.description_custom_view_summary_filters");

        $form->hasManyTable('custom_view_grid_filters', exmtrans("custom_view.custom_view_grid_filters"), function ($form) use ($custom_table, $column_options) {
            $targetOptions = $custom_table->getColumnsSelectOptions($column_options);

            $field = $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                ->options($targetOptions);

            if (boolval(config('exment.form_column_option_group', false))) {
                $targetGroups = static::convertGroups($targetOptions, $custom_table);
                $field->groups($targetGroups);
            }

            $form->hidden('order')->default(0);
        })->setTableColumnWidth(8, 4)
        ->rowUpDown('order', 10)
        ->descriptionHtml($description);
    }


    /**
     * get group condition
     */
    public static function getGroupCondition($view_column_target = null)
    {
        if (!isset($view_column_target)) {
            return [];
        }

        // get column item from $view_column_target
        $columnItem = CustomViewColumn::getColumnItem($view_column_target);
        if (!isset($columnItem)) {
            return [];
        }

        if (!$columnItem->isDate()) {
            return [];
        }

        // if date, return option
        $options = GroupCondition::getOptions();
        return collect($options)->map(function ($array) {
            return ['id' => array_get($array, 'id'), 'text' => exmtrans('custom_view.group_condition_options.'.array_get($array, 'name'))];
        });
    }


    public function getCallbackGroupKeys($model)
    {
        $keys = [];
        foreach ($this->custom_view->custom_view_columns_cache as $group_column) {
            $column_item = $group_column->column_item;
            if (!$column_item) {
                continue;
            }

            $uniqueName = $column_item->uniqueName();
            if (is_nullorempty($uniqueName)) {
                continue;
            }

            $keys[$uniqueName] = array_get($model, $uniqueName);
        }

        return $keys;
    }

    /**
     * set grid filter
     */
    protected function setCustomGridFilters($grid, $ajax = false)
    {
        $grid->filter(function ($filter) use ($ajax) {
            $filter->disableIdFilter();
            $filter->setAction($this->getFilterUrl());

            if (config('exment.custom_value_filter_ajax', true) && !$ajax && !boolval(request()->get('execute_filter'))) {
                $filter->setFilterAjax(admin_urls_query('data', $this->custom_table->table_name, ['filter_ajax' => 1]));
                return;
            }

            $filterItems = $this->getFilterColumns($filter);

            // set filter item
            if (count($filterItems) <= 6) {
                foreach ($filterItems as $filterItem) {
                    $filterItem->setAdminFilter($filter);
                }
            } else {
                $separate = floor(count($filterItems) /  2);
                $filter->column(1/2, function ($filter) use ($filterItems, $separate) {
                    for ($i = 0; $i < $separate; $i++) {
                        $filterItems[$i]->setAdminFilter($filter);
                    }
                });
                $filter->column(1/2, function ($filter) use ($filterItems, $separate) {
                    for ($i = $separate; $i < count($filterItems); $i++) {
                        /** @var int $i */
                        $filterItems[$i]->setAdminFilter($filter);
                    }
                });
            }
        });
    }

    /**
     * Get filter url.
     * *Modal appends query URL.*
     *
     * @return string
     */
    protected function getFilterUrl(): string
    {
        $query = array_filter(request()->all([
            '_scope_',
        ]));
        return admin_urls_query('data', $this->custom_table->table_name, $query);
    }

    /**
     * Get filter html. call from ajax, or execute set filter.
     *
     * @return array offset 0 : html, 1 : script
     */
    public function getFilterHtml()
    {
        $classname = getModelName($this->custom_table);
        $grid = new Grid(new $classname());

        $this->setCustomGridFilters($grid, true);

        // get html force
        $html = null;
        $grid->filter(function ($filter) use (&$html) {
            $html = $filter->render();
        });

        return ['html' => $html, 'script' => \Admin::purescript()->render()];
    }

    /**
     * Get filter showing columns
     */
    protected function getFilterColumns($filter): Collection
    {
        $filterItems = [];

        // if has custom_view_grid_filters, set as value
        $custom_view_grid_filters = $this->custom_view->custom_view_grid_filters;
        if (count($custom_view_grid_filters) > 0) {
            $service = $this->custom_view->getSearchService()->setQuery($filter->model());

            foreach ($custom_view_grid_filters as $custom_view_grid_filter) {
                $service->setRelationJoin($custom_view_grid_filter, [
                    'asSummary' => true,
                ]);

                $filterItems[] = $custom_view_grid_filter->column_item;
            }

            /** @var Collection $collection */
            $collection =  collect($filterItems);
            return $collection;
        }

        foreach (SystemColumn::getOptions(['grid_filter' => true, 'grid_filter_system' => true]) as $filterKey => $filterType) {
            if ($this->custom_table->gridFilterDisable($filterKey)) {
                continue;
            }

            $filterItems[] = ColumnItems\SystemItem::getItem($this->custom_table, $filterKey);
        }

        // check relation
        $this->setRelationFilter($filterItems);

        // filter workflow
        if (!is_null($workflow = Workflow::getWorkflowByTable($this->custom_table))) {
            foreach (SystemColumn::getOptions(['grid_filter' => true, 'grid_filter_system' => false]) as $filterKey => $filterType) {
                if (!SystemColumn::isWorkflow($filterKey)) {
                    continue;
                }
                if ($this->custom_table->gridFilterDisable($filterKey)) {
                    continue;
                }

                $filterItems[] = ColumnItems\WorkflowItem::getItem($this->custom_table, $filterKey);
            }
        }

        // filter comment
        if (boolval($this->custom_table->getOption('comment_flg')?? true)) {
            foreach (SystemColumn::getOptions(['grid_filter' => true, 'grid_filter_system' => false]) as $filterKey => $filterType) {
                if (!SystemColumn::isComment($filterKey)) {
                    continue;
                }
                if ($this->custom_table->gridFilterDisable($filterKey)) {
                    continue;
                }

                $filterItems[] = ColumnItems\CommentItem::getItem($this->custom_table);
            }
        }

        // loop custom column
        $this->setColumnFilter($filterItems);

        return collect($filterItems);
    }

    /**
     * Set relation filter. Consider modal.
     *
     * @return void
     */
    protected function setRelationFilter(&$filterItems)
    {
        // check relation
        $relation = CustomRelation::getRelationByChild($this->custom_table);
        // if set, create select
        if (!isset($relation)) {
            return;
        }

        $column_item = ColumnItems\ParentItem::getItemWithRelation($this->custom_table, $relation);
        $filterItems[] = $column_item;
    }

    /**
     * Set column filter. Consider modal.
     *
     * @return void
     */
    protected function setColumnFilter(&$filterItems)
    {
        // if modal, skip
        $search_column_select = null;
        $searchType = null;

        // get search_enabled_columns and loop
        $search_enabled_columns = $this->custom_table->getSearchEnabledColumns();
        foreach ($search_enabled_columns as $search_column) {
            $filterItems[] = $search_column->column_item;
        }
    }
}
