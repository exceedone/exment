<?php

namespace Exceedone\Exment\DataItems\Grid;

use ExmentAdminCore\Admin\Grid;
use ExmentAdminCore\Admin\Grid\Linker;
use Exceedone\Exment\Grid\Tools as GridTools;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Form\Widgets\SelectItemBox;
use Exceedone\Exment\Model\RelationTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\ColumnItems;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Enums\PluginEventTrigger;
use Exceedone\Exment\Services\PartialCrudService;
use Illuminate\Http\Request;
use ExmentAdminCore\Admin\Form;
// todo 一覧ソートバグ対応用の追加です
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class DefaultGrid extends GridBase
{
    /**
     * Request key + column item of every filter the grid registered.
     *
     * Collected while the filters are being built because that is the
     * only place both halves exist at once: the key is decided inside
     * setAdminFilter, and the item is what can turn a stored value back
     * into readable text. GridFilterChips needs the two together.
     *
     * @var array<int, array<string, mixed>>
     */
    protected $filterChipPairs = [];

    // @phpstan-ignore-next-line
    public function __construct($custom_table, $custom_view)
    {
        $this->custom_table = $custom_table;
        $this->custom_view = $custom_view;
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    public function grid()
    {
        $classname = getModelName($this->custom_table);
        $grid = new Grid(new $classname());

        // if modal, Change view model
        if ($this->modal) {
            $this->gridFilterForModal($grid, $this->callback);
            $db_table_name = getDBTableName($this->custom_table);
            $grid->model()->select("$db_table_name.*");
        } else {
            // filter
            $this->custom_view->resetSearchService();
            $this->custom_view->filterSortModel($grid->model(), ['callback' => $this->callback]);
        }

        $this->setCustomGridFilters($grid);

        // create grid
        $this->setGrid($grid);

        // manage row action
        $this->manageRowAction($grid);

        // manage tool button
        $this->manageMenuToolButton($grid);

        $grid->getDataCallback(function ($grid) {
            $customValueCollection = $grid->getOriginalCollection();
            $this->custom_table->setSelectTableValues($customValueCollection);
        });

        // if modal, append to selectitem button
        if ($this->modal) {
            $this->appendSelectItemButton($grid);
        }

        // A preview has one page and no pager. The rows are still fetched a
        // page at a time - a table of 100,000 rows must not be read whole to
        // show what a view looks like - but every pager link is a GET back to
        // the preview url, which no longer holds the unsaved settings.
        if ($this->preview) {
            $grid->option('show_pagination', false);
        }

        return $grid;
    }


    /**
     * Get database query
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder $query
     * @param array $options
     * @return \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder
     */
    // @phpstan-ignore-next-line
    public function getQuery($query, array $options = [])
    {
        // Now only execute filter Model
        return $this->custom_view->filterSortModel($query, $options);
    }


    /**
     * set laravel-admin grid using custom_view
     */
    // @phpstan-ignore-next-line
    public function setGrid($grid)
    {
        $custom_table = $this->custom_table;
        // set table header attributes
        $grid->setHeaderAttributes($this->custom_view->getHeaderOptions());
        // get view columns
        $custom_view_columns = $this->custom_view->custom_view_columns_cache;
        // Whether the current user may inline-edit a row on this table. Read
        // once so the per-column loop below does one array walk instead of
        // one permission lookup per column, and so grid_tools.js only sees
        // `.exm-editable` cells when the write path is actually open.
        $inlineEditAllowed = $this->custom_table->hasPermission(\Exceedone\Exment\Enums\Permission::AVAILABLE_EDIT_CUSTOM_VALUE);
        foreach ($custom_view_columns as $custom_view_column) {
            $item = $custom_view_column->column_item;
            if (!isset($item)) {
                continue;
            }

            $item = $item->label(array_get($custom_view_column, 'view_column_name'))
                ->options([
                    'grid_column' => true,
                    'view_pivot_column' => $custom_view_column->view_pivot_column_id ?? null,
                    'view_pivot_table' => $custom_view_column->view_pivot_table_id ?? null,
                    'header_align' => $this->custom_view->header_align ?? null,
                ]);
            //$name = $item->indexEnabled() ? $item->index() : $item->uniqueName();
            $className = 'column-' . $item->name();
            $classes = [$className];
            // Mark cells the inline editor can edit. `.exm-editable` is what
            // grid_tools.js listens to on double-click; the badge and colour
            // are added elsewhere.
            //
            // The whole decision lives in GridInlineEditor so this class and
            // the JSON config it renders can never disagree - a cell marked
            // here but missing from the config would open nothing on double
            // click. That includes refusing a column of a related table or a
            // pivot column: the editor PUTs to this table's endpoint only.
            $inlineEditable = $inlineEditAllowed
                && GridTools\GridInlineEditor::isEditableColumn($custom_view_column, $this->custom_table);
            if ($inlineEditable) {
                $classes[] = 'exm-editable';
            }
            $grid->column($item->uniqueName(), $item->label())
                ->sort($item->sortable())
                ->sortName($item->getSortName())
                //->cast($item->getCastName())
                // todo 一覧ソートバグ対応用の修正です パラメータ$queryの頭に&が追加されているのに注意
                ->sortCallback(function (&$query, $args) use ($custom_view_column) {
                    if ($query instanceof Model) {
                        $query = $query->newQuery();
                    }
                    $this->custom_view->getSearchService()->setQuery($query)->addSelect()->orderByCustomViewColumn($custom_view_column, (count($args) > 0 ? $args[0] : 'asc'));
                })
                ->style($item->gridStyle())
                ->setClasses($classes)
                ->setHeaderStyle($item->gridHeaderStyle())
                ->display(function ($v) use ($item, $inlineEditable) {
                    // @phpstan-ignore-next-line
                    if (is_null($this)) {
                        return '';
                    }
                    $html = $item->setCustomValue($this)->html();
                    if (!$inlineEditable) {
                        return $html;
                    }
                    // A long value is shown shortened ('...'), a formatted
                    // number rounded - either way the cell no longer says the
                    // stored value, and the inline editor prefills from the
                    // cell. The marker carries the value the editor must
                    // start from, so what it saves is an edit of the data,
                    // not of the display.
                    return (string)$html . GridTools\GridInlineEditor::rawValueTag($item, (string)$html);
                })->escape(false);
        }

        // set parpage
        $pager_count = $this->custom_view->pager_count;
        if (is_null(request()->get('per_page')) && isset($pager_count) && is_numeric($pager_count) && $pager_count > 0) {
            $grid->paginate(intval($pager_count));
        }

        $grid_per_pages = stringToArray(config('exment.grid_per_pages'));
        if (empty($grid_per_pages)) {
            $grid_per_pages = Define::PAGER_GRID_COUNTS;
        }
        $grid->perPages($grid_per_pages);

        // set with
        $custom_table->setQueryWith($grid->model(), $this->custom_view);
    }


    /**
     * execute filter for modal
     * *PLEASE append func "getFilterUrl" logic if append query logic.*
     *
     * @return void
     */
    // @phpstan-ignore-next-line
    protected function gridFilterForModal($grid, $filter_func)
    {
        // set request session data url disabled;
        System::setRequestSession(Define::SYSTEM_KEY_SESSION_DISABLE_DATA_URL_TAG, true);

        $modal_target_view = CustomView::getEloquent(request()->get('target_view_id'));

        // modal use alldata view
        $this->custom_view = CustomView::getAllData($this->custom_table);

        // filter using modal_target_view, and display table
        if (isset($modal_target_view)) {
            $modal_target_view->filterSortModel($grid->model(), ['callback' => $filter_func]);
        }

        // filter display table
        $modal_display_table = CustomTable::getEloquent(request()->get('display_table_id'));
        $modal_custom_column = CustomColumn::getEloquent(request()->get('target_column_id'));
        if (!empty($modal_display_table) && !empty($modal_custom_column)) {
            $this->custom_table->filterDisplayTable($grid->model(), $modal_display_table, [
                'all' => $modal_custom_column->isGetAllUserOrganization(),
            ]);
        }

        ///// If set linkage, filter relation.
        // get children table id
        $expand = request()->get('linkage');
        if (!is_nullorempty($expand)) {
            RelationTable::setQuery($grid->model(), array_get($expand, 'search_type'), array_get($expand, 'linkage_value_id'), [
                'parent_table' => CustomTable::getEloquent(array_get($expand, 'parent_select_table_id')),
                'child_table' => CustomTable::getEloquent(array_get($expand, 'child_select_table_id')),
            ]);
        }
    }

    /**
     * Get filter url.
     * *Modal appends query URL.*
     *
     * @return string
     */
    protected function getFilterUrl(): string
    {
        if (!$this->modal) {
            $query = array_filter(request()->all([
                '_scope_',
            ]));
        } else {
            $query = array_filter(request()->all([
                'target_view_id',
                'display_table_id',
                'target_column_id',
                'linkage',
            ]));
            $query['modal'] = 1;
        }

        return admin_urls_query('data', $this->custom_table->table_name, $query);
    }

    /**
     * Get filter html. call from ajax, or execute set filter.
     *
     * @return array offset 0 : html, 1 : script
     */
    // @phpstan-ignore-next-line
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
     * set grid filter
     */
    // @phpstan-ignore-next-line
    protected function setCustomGridFilters($grid, $ajax = false)
    {
        $grid->quickSearch(function ($model, $input) {
            $eloquent = $model->eloquent();
            // Only call setSearchQueryOrWhere if exists. (If export, sometimes $eloquent is not Model.)
            if (method_exists($eloquent, 'setSearchQueryOrWhere')) {
                // @phpstan-ignore-next-line
                $eloquent->setSearchQueryOrWhere($model, $input, ['searchDocument' => true,]);
            }
        }, 'left');

        $grid->filter(function ($filter) use ($ajax) {
            $filter->disableIdFilter();
            $filter->setAction($this->getFilterUrl());

            if ($this->custom_table->enableShowTrashed() === true && !$this->modal) {
                $filter->scope('trashed', exmtrans('custom_value.soft_deleted_data'))->onlyTrashed();
            }

            if (config('exment.custom_value_filter_ajax', true) && !$ajax && !$this->modal && !boolval(request()->get('execute_filter'))) {
                $filter->setFilterAjax(admin_urls_query('data', $this->custom_table->table_name, ['filter_ajax' => 1]));
                return;
            }

            $filterItems = $this->getFilterColumns($filter);

            // set filter item
            if (count($filterItems) <= 6) {
                foreach ($filterItems as $filterItem) {
                    $this->setAdminFilterAndRemember($filterItem, $filter);
                }
            } else {
                $separate = floor(count($filterItems) /  2);
                $filter->column(1/2, function ($filter) use ($filterItems, $separate) {
                    for ($i = 0; $i < $separate; $i++) {
                        $this->setAdminFilterAndRemember($filterItems[$i], $filter);
                    }
                });
                $filter->column(1/2, function ($filter) use ($filterItems, $separate) {
                    for ($i = $separate; $i < count($filterItems); $i++) {
                        /** @var int $i */
                        $this->setAdminFilterAndRemember($filterItems[$i], $filter);
                    }
                });
            }
        });
    }


    /**
     * Register one column item's filter and note which request key it took.
     *
     * The key is built inside setAdminFilter from state this class cannot
     * read (`uniqueName` is protected, and calling the public
     * `uniqueName()` would GENERATE one where none was set - a different
     * string from the one the filter is already using). Reading it back
     * off the filter that was just added is therefore the only way to get
     * the two sides to agree.
     *
     * @param mixed $filterItem
     * @param mixed $filter
     * @return void
     */
    protected function setAdminFilterAndRemember($filterItem, &$filter)
    {
        $before = count($filter->filters());
        $filterItem->setAdminFilter($filter);

        foreach (array_slice($filter->filters(), $before) as $added) {
            $this->filterChipPairs[] = [
                'key' => $added->getColumn(),
                'item' => $filterItem,
            ];
        }
    }

    /**
     * Get filter showing columns
     */
    // @phpstan-ignore-next-line
    protected function getFilterColumns($filter): \Illuminate\Support\Collection
    {
        $filterItems = [];

        // if has custom_view_grid_filters, set as value
        $custom_view_grid_filters = $this->custom_view->custom_view_grid_filters;
        if (count($custom_view_grid_filters) > 0) {
            $service = $this->custom_view->getSearchService()->setQuery($filter->model());

            foreach ($custom_view_grid_filters as $custom_view_grid_filter) {
                $service->setRelationJoin($custom_view_grid_filter);

                $filterItems[] = $custom_view_grid_filter->column_item;
            }

            /** @var Collection $collection */
            // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
    protected function setRelationFilter(&$filterItems)
    {
        // check relation
        $relation = CustomRelation::getRelationByChild($this->custom_table);
        // if set, create select
        if (!isset($relation)) {
            return;
        }

        // if modal, checking relatin type
        if ($this->modal) {
            $searchType = array_get(request()->get('linkage'), 'search_type');
            if (isMatchString($searchType, $relation->relation_type)) {
                return;
            }
        }

        $column_item = ColumnItems\ParentItem::getItemWithRelation($this->custom_table, $relation);
        $filterItems[] = $column_item;
    }


    /**
     * Set column filter. Consider modal.
     *
     * @return void
     */
    // @phpstan-ignore-next-line
    protected function setColumnFilter(&$filterItems)
    {
        // if modal, skip
        $search_column_select = null;
        $searchType = null;
        if ($this->modal) {
            $linkage = request()->get('linkage');
            $searchType = array_get($linkage, 'search_type');
            $parent_table = CustomTable::getEloquent(array_get($linkage, 'parent_select_table_id'));
            $child_table = CustomTable::getEloquent(array_get($linkage, 'child_select_table_id'));
            if (isset($parent_table) && isset($child_table)) {
                $search_column_select = $child_table->getSelectTableColumns($parent_table)->first();
            }
        }

        // get search_enabled_columns and loop
        $search_enabled_columns = $this->custom_table->getSearchEnabledColumns();
        foreach ($search_enabled_columns as $search_column) {
            // if modal, checking relatin type
            if ($this->modal) {
                if (isMatchString($searchType, SearchType::SELECT_TABLE) && isset($search_column_select) && isMatchString($search_column_select->id, $search_column->id)) {
                    continue;
                }
            }

            $filterItems[] = $search_column->column_item;
        }
    }

    /**
     * Manage Grid Tool Button
     * And Manage Batch Action
     */
    // @phpstan-ignore-next-line
    protected function manageMenuToolButton($grid)
    {
        if ($this->modal || $this->preview) {
            $grid->disableRowSelector();
            $grid->disableCreateButton();
            $grid->disableExport();
            return;
        }

        $custom_table = $this->custom_table;
        $grid->disableCreateButton();
        $grid->disableExport();

        // create exporter
        $service = $this->getImportExportService($grid);
        $grid->exporter($service);

        $grid->tools(function (Grid\Tools $tools) use ($grid) {
            $listButtons = Plugin::pluginPreparingButton(PluginEventTrigger::GRID_MENUBUTTON, $this->custom_table);

            // Display helpers. They only change how the current page looks,
            // so they come first and stay out of the way of the buttons
            // that actually do something (new, import/export, view menu).
            //
            // GridColumnVisibility replaces laravel-admin's own column
            // selector, so make sure the stock one stays off here too
            // instead of relying on the global Grid::init in
            // Middleware\Initialize - two column buttons side by side
            // would be worse than none.
            $grid->disableColumnSelector();
            // Reads what the request is filtering on and says so on the
            // grid. Appended first so it renders above the button row.
            $tools->append(new GridTools\GridFilterChips($this->custom_table, $this->filterChipPairs));
            $tools->append(new GridTools\GridColumnVisibility($grid));
            $tools->append(new GridTools\GridColumnPin($grid, $this->custom_table->table_name));
            $tools->append(new GridTools\GridGroupBy($grid, $this->custom_table->table_name));
            $tools->append(new GridTools\GridDensity());
            $tools->append(new GridTools\GridAutoRefresh($this->custom_table->table_name));

            // Second entrance to the batch actions rendered further left.
            // It is fixed to the bottom of the viewport, so it is appended
            // here only to get it inside #pjax-container - a bar left over
            // from the previous page would keep an obsolete selection
            // count on screen. The custom_table and custom_view arguments
            // let it decide whether to expose bulk-edit / bulk-export
            // buttons alongside the actions copied from the stock batch
            // dropdown - both features only make sense when the table
            // permits them.
            $tools->append(new GridTools\GridBulkBar($this->custom_table, $this->custom_view));

            // Right-click menu carrier. The div is invisible until
            // grid_tools.js positions it at the cursor - kept inside the
            // toolbar (i.e. inside #pjax-container) so a page swap
            // rebuilds it with the same current CSRF token instead of
            // leaving one behind on <body>.
            $tools->append(new GridTools\GridContextMenu($this->custom_table));

            // JSON config for the inline editor. Renders no visible
            // widget - just a `<script>` tag grid_tools.js reads on boot
            // to learn which columns can be edited in place and how each
            // one's picker should look. Skipped without edit permission.
            $tools->append(new GridTools\GridInlineEditor($this->custom_table, $this->custom_view));

            // The action buttons. gridTool() drops their classic float-end
            // wrapper, so unlike the stock header they render in DOM order
            // - which is why this block appends them in the order they have
            // always appeared on screen (floats stacked them in reverse):
            // plugins, view menu, table settings, create, import/export.
            // Same look on the PC, and the phone flex layout (which never
            // honored the floats) now reads in the same order instead of
            // mirrored. It also gives every button the shared
            // .exm-grid-tool spacing - the floats sat flush against the
            // display tools above.

            // add plugin button
            if ($listButtons !== null && count($listButtons) > 0) {
                foreach ($listButtons as $listButton) {
                    $tools->append((new Tools\PluginMenuButton($listButton, $this->custom_table))->gridTool());
                }
            }

            if ($this->custom_table->enableViewMenuButton()) {
                /** @phpstan-ignore-next-line append() expects ExmentAdminCore\Admin\Grid\Tools\AbstractTool|string, Exceedone\Exment\Form\Tools\CustomViewMenuButton given */
                $tools->append((new Tools\CustomViewMenuButton($this->custom_table, $this->custom_view))->gridTool());
            }

            // add page change button(contains view seting)
            if ($this->custom_table->enableTableMenuButton()) {
                /** @phpstan-ignore-next-line append() expects ExmentAdminCore\Admin\Grid\Tools\AbstractTool|string, Exceedone\Exment\Form\Tools\CustomTableMenuButton given */
                $tools->append((new Tools\CustomTableMenuButton('data', $this->custom_table))->gridTool());
            }

            if ($this->custom_table->enableCreate(true) === true) {
                $tools->append(view('exment::custom-value.new-button', ['table_name' => $this->custom_table->table_name, 'grid_tool' => true]));
            }

            // validate export and import
            $import = $this->custom_table->enableImport();
            $export = $this->custom_table->enableExport();
            if ($import === true || $export === true) {
                // todo 通常ビューの場合のみプラグインエクスポートを有効にするための修正です
                $button = new Tools\ExportImportButton(admin_urls('data', $this->custom_table->table_name), $grid, $export === true, $export === true, $import === true, $export === true);
                /** @phpstan-ignore-next-line append() expects ExmentAdminCore\Admin\Grid\Tools\AbstractTool|string, Exceedone\Exment\Form\Tools\ExportImportButton given */
                $tools->append($button->setCustomTable($this->custom_table)->gridTool());
            }

            // manage batch --------------------------------------------------
            $tools->batch(function ($batch) {
                if ($this->custom_table->enableEdit() === true) {
                    if (request()->get('_scope_') == 'trashed' && $this->custom_table->enableShowTrashed() === true) {
                        $batch->disableDelete();
                        $batch->add(exmtrans('custom_value.restore'), new GridTools\BatchRestore());
                        $batch->add(exmtrans('custom_value.hard_delete'), new GridTools\BatchHardDelete(exmtrans('custom_value.hard_delete')));
                    } else {
                        foreach ($this->custom_table->custom_operations as $custom_operation) {
                            if ($custom_operation->active_flg && $custom_operation->matchOperationType(Enums\CustomOperationType::BULK_UPDATE)) {
                                $title = $custom_operation->getOption('button_label') ?? $custom_operation->operation_name;
                                $batch->add($title, new GridTools\BatchUpdate($custom_operation));
                            }
                        }
                    }
                } else {
                    // if cannot edit, disable delete and update operations
                    $batch->disableDelete();
                }
            });
        });
    }

    /**
     * Management row action
     */
    // @phpstan-ignore-next-line
    protected function manageRowAction($grid)
    {
        if ($this->modal || $this->preview) {
            $grid->disableActions();
            return;
        }

        if (isset($this->custom_table)) {
            // name
            $custom_table = $this->custom_table;
            $relationTables = $custom_table->getRelationTables();

            $grid->actions(function (Grid\Displayers\Actions $actions) use ($custom_table, $relationTables) {
                /** @var mixed $actions */
                $custom_table->setGridAuthoritable($actions->grid->getOriginalCollection());
                $enableCreate = true;
                $enableEdit = true;
                $enableDelete = true;
                $enableHardDelete = false;

                // if has relations, add link
                if (count($relationTables) > 0) {
                    $linker = (new Linker())
                        ->url($actions->row->getRelationSearchUrl())
                        ->icon('fa-compress')
                        ->tooltip(exmtrans('search.header_relation'));
                    $actions->prepend($linker);
                }

                // append restore url
                if ($actions->row->trashed() && $custom_table->enableEdit() === true && $custom_table->enableShowTrashed() === true) {
                    $enableHardDelete = true;
                }

                // if user does't edit permission disable edit row.
                if ($actions->row->enableEdit(true) !== true) {
                    $enableEdit = false;
                }

                if ($actions->row->enableDelete(true) !== true) {
                    $enableDelete = false;
                }

                if ($custom_table->enableCreate(true) !== true) {
                    $enableCreate = false;
                }

                if (!is_null($parent_value = $actions->row->getParentValue(null, true))) {
                    if (boolval($custom_table->getOption('editable_with_parent')??1)) {
                        if ($parent_value->enableEdit(true) !== true) {
                            $enableCreate = false;
                            $enableEdit = false;
                            $enableDelete = false;
                        }
                    } elseif ($parent_value->enableAccess() !== true)  {
                        $enableCreate = false;
                        $enableEdit = false;
                        $enableDelete = false;
                    }
                }

                if (!$enableEdit) {
                    $actions->disableEdit();
                }

                if (!$enableDelete) {
                    $actions->disableDelete();
                }

                if ($enableHardDelete) {
                    $actions->disableView();
                    $actions->disableDelete();

                    // if parent data does not exist or has not been deleted 
                    if (!$parent_value || !$parent_value->trashed()) {
                        // add restore link
                        $restoreUrl = $actions->row->getUrl() . '/restoreClick';
                        $linker = (new Linker())
                            ->icon('fa-undo')
                            ->script(true)
                            ->linkattributes([
                                'data-add-swal' => $restoreUrl,
                                'data-add-swal-title' => exmtrans('custom_value.restore'),
                                'data-add-swal-text' => exmtrans('custom_value.message.restore'),
                                'data-add-swal-method' => 'get',
                                'data-add-swal-confirm' => trans('admin.confirm'),
                                'data-add-swal-cancel' => trans('admin.cancel'),
                            ])
                            ->tooltip(exmtrans('custom_value.restore'));
                        $actions->append($linker);
                    }

                    // append show url
                    $showUrl = $actions->row->getUrl() . '?trashed=1';
                    // add new edit link
                    $linker = (new Linker())
                        ->url($showUrl)
                        ->icon('fa-eye')
                        ->tooltip(trans('admin.show'));
                    $actions->append($linker);

                    // add hard delete link
                    $deleteUrl = $actions->row->getUrl() . '?trashed=1';
                    $linker = (new Linker())
                        ->icon('fa-trash')
                        ->script(true)
                        ->linkattributes([
                            'data-add-swal' => $deleteUrl,
                            'data-add-swal-title' => exmtrans('custom_value.hard_delete'),
                            'data-add-swal-text' => exmtrans('custom_value.message.hard_delete'),
                            'data-add-swal-method' => 'delete',
                            'data-add-swal-confirm' => trans('admin.confirm'),
                            'data-add-swal-cancel' => trans('admin.cancel'),
                        ])
                        ->tooltip(exmtrans('custom_value.hard_delete'));
                    $actions->append($linker);

                } elseif ($actions->row->trashed()) {
                    $actions->disableView();
                    $actions->disableDelete();
                    // append show url
                    $showUrl = $actions->row->getUrl() . '?trashed=1';
                    // add new edit link
                    $linker = (new Linker())
                        ->url($showUrl)
                        ->icon('fa-eye')
                        ->tooltip(trans('admin.show'));
                    $actions->append($linker);
                }

                if ($enableCreate && boolval(config('exment.gridrow_show_copy_button', false))) {
                    $linker = (new Linker())
                        ->url(admin_urls('data', $custom_table->table_name, "create?copy_id={$actions->row->id}"))
                        ->icon('fa-copy')
                        ->tooltip(exmtrans('common.copy_item', exmtrans('custom_value.custom_valule_button_label')));
                    $actions->append($linker);
                }

                PartialCrudService::setGridRowAction($custom_table, $actions);
            });
        }
    }

    // @phpstan-ignore-next-line
    public function renderModalFrame()
    {
        // get target column id or class
        $custom_column = CustomColumn::getEloquent(request()->get('target_column_id'));
        $target_column_class = request()->get('target_column_class');
        $target_column_multiple = request()->get('target_column_multiple') ?? (isset($custom_column) ? boolval($custom_column->getOption('multiple_enabled')) : false);
        $widgetmodal_uuid = request()->get('widgetmodal_uuid');

        $items = $this->custom_table->getValueQuery()->whereOrIn('id', stringToArray(request()->get('selected_items')))->get();

        $url = request()->fullUrl() . '&modal=1';
        return getAjaxResponse([
            'title' => trans('admin.search') . ' : ' . $this->custom_table->table_view_name,
            'body'  => (new SelectItemBox(
                $url,
                $target_column_class,
                $widgetmodal_uuid,
                [[
                'name' => 'select',
                'label' =>  trans('admin.choose'),
                'multiple' => boolval($target_column_multiple),
                'icon' => $this->custom_table->getOption('icon'),
                'background_color' =>  $this->custom_table->getOption('color') ?? '#3c8dbc', //if especially
                'color' => '#FFFFFF',
                'items' => $items->map(function ($item) {
                    return [
                        'value' => $item->id,
                        'label' => $item->getLabel(),
                    ];
                })->toArray(),
            ],
            ]
            ))->render(),
            'submitlabel' => trans('admin.setting'),
            'modalSize' => 'modal-xl',
            'modalClass' => 'modal-selectitem modal-heightfix modal-body-overflow-hidden',
            'preventSubmit' => true,
        ]);
    }

    // @phpstan-ignore-next-line
    public function renderModal($grid)
    {
        return view('exment::widgets.partialindex', [
            'content' => $grid->render()
        ]);
    }

    /**
     * Append select item button in grid
     *
     * @param Grid $grid
     * @return void
     */
    protected function appendSelectItemButton($grid)
    {
        $grid->column('modal_selectitem', trans('admin.action'))->display(function ($a, $b, $model) {
            return view('exment::tools.selectitem-button', [
                'model' => $model,
                'value' => $model->id,
                'valueLabel' => $model->getLabel(),
                'label' => exmtrans('common.append_to_selectitem'),
                'target_selectitem' => 'select',
            ])->render();
        })->escape(false);
    }

    /**
     * Set custom view columns form. For controller.
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @return void
     */
    // @phpstan-ignore-next-line
    public static function setViewForm($view_kind_type, $form, $custom_table, array $options = [])
    {
        if (in_array($view_kind_type, [Enums\ViewKindType::DEFAULT, Enums\ViewKindType::ALLDATA])) {
            $grid_per_pages = stringToArray(config('exment.grid_per_pages'));
            if (empty($grid_per_pages)) {
                $grid_per_pages = Define::PAGER_GRID_COUNTS;
            }

            $form->select('pager_count', exmtrans("common.pager_count"))
                ->required()
                ->options(getPagerOptions(true, $grid_per_pages))
                ->disableClear()
                ->default(0);

            $form->select('header_align', exmtrans("custom_view.header_align"))
                ->options(Enums\TextAlignExType::transArray('custom_view.align_type_options'))
            ;
        }

        // column setting
        if ($view_kind_type != Enums\ViewKindType::FILTER) {
            static::setViewInfoboxFields($form);

            static::setColumnFields($form, $custom_table);
        }

        // filter setting
        if ($view_kind_type != Enums\ViewKindType::ALLDATA) {
            static::setFilterFields($form, $custom_table);
        }

        static::setSortFields($form, $custom_table, true);

        if (in_array($view_kind_type, [Enums\ViewKindType::DEFAULT, Enums\ViewKindType::ALLDATA])) {
            static::setGridFilterFields($form, $custom_table);
        }
    }



    /**
     * Set column gridfilter item form
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @return void
     */
    // @phpstan-ignore-next-line
    public static function setGridFilterFields(&$form, $custom_table, array $column_options = [])
    {
        // columns setting
        $column_options = array_merge([
            'append_table' => true,
            'include_parent' => true,
            'include_workflow' => true,
            'include_comment' => true,
            'index_enabled_only' => true,
            'only_system_grid_filter' => true,
            'ignore_many_to_many' => true,
            'ignore_multiple_refer' => true,
        ], $column_options);

        $manualUrl = getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'));

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
        ->descriptionHtml(exmtrans("custom_view.description_custom_view_grid_filters", $manualUrl));
    }

    /**
     * Set filter fileds form
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @param boolean $is_aggregate
     * @return void
     */
    public static function setFilterFields(&$form, $custom_table, $is_aggregate = false)
    {
        parent::setFilterFields($form, $custom_table, $is_aggregate);

        $form->checkboxone('condition_reverse', exmtrans("condition.condition_reverse"))
            ->option(exmtrans("condition.condition_reverse_options"));
    }
}
