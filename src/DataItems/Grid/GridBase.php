<?php

namespace Exceedone\Exment\DataItems\Grid;

use ExmentAdminCore\Admin\Admin;
use ExmentAdminCore\Admin\Form;
use ExmentAdminCore\Admin\Grid;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CellStylePreset;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Form\Tools\ConditionHasManyTable;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Services\DataImportExport;
use Illuminate\Http\Request;

abstract class GridBase
{
    // @phpstan-ignore-next-line
    protected $custom_table;
    // @phpstan-ignore-next-line
    protected $custom_view;
    // @phpstan-ignore-next-line
    protected $modal = false;
    /**
     * Drawn from settings that were never saved, on the preview screen.
     *
     * Everything the view can be asked to do lives behind a url that reads the
     * saved view: a card moved here, a record created here, a second page
     * fetched here would all be answered from the database, not from the
     * settings on screen. So a preview shows and does nothing.
     *
     * @var bool
     */
    // @phpstan-ignore-next-line
    protected $preview = false;
    // @phpstan-ignore-next-line
    protected $callback;

    // @phpstan-ignore-next-line
    public static function getItem(...$args)
    {
        list($custom_table, $custom_view) = $args + [null, null];

        /** Unsafe usage of new static(). */
        /** @phpstan-ignore-next-line */
        return new static($custom_table, $custom_view);
    }

    // @phpstan-ignore-next-line
    public function modal(bool $modal)
    {
        $this->modal = $modal;

        return $this;
    }

    /**
     * Draw this view as a read-only preview.
     *
     * @param bool $preview
     * @return $this
     */
    // @phpstan-ignore-next-line
    public function preview(bool $preview)
    {
        $this->preview = $preview;

        return $this;
    }

    /**
     * @return bool
     */
    public function isPreview(): bool
    {
        return $this->preview;
    }

    // @phpstan-ignore-next-line
    public function callback($callback)
    {
        $this->callback = $callback;

        return $this;
    }

    // @phpstan-ignore-next-line
    public function renderModal($grid)
    {
        return [];
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
        return $query;
    }

    /**
     * set laravel-admin grid using custom_view
     */
    // @phpstan-ignore-next-line
    public function setGrid($grid)
    {
    }

    /**
     * Get callback filter function
     *
     * @return \Closure|null
     */
    public function getCallbackFilter()
    {
        $group_keys = json_decode_ex(request()->query('group_key'));
        if (is_nullorempty($group_keys)) {
            return null;
        }
        $group_view = CustomView::findBySuuid(request()->query('group_view'));
        if (is_nullorempty($group_view)) {
            return null;
        }

        // replace view
        $this->custom_view = CustomView::getAllData($this->custom_table);
        $service = $this->custom_view->getSearchService();
        $group_view->setSearchService($service);
        
        $filters = [];
        // @phpstan-ignore-next-line
        foreach ($group_keys as $key => $value) {
            $custom_view_column = CustomViewColumn::findByCkey($key);
            $column_item = $custom_view_column->column_item;
            $custom_view_filter = new CustomViewFilter();
            $custom_view_filter->custom_view_id = $custom_view_column->custom_view_id;
            $custom_view_filter->view_column_type = $custom_view_column->view_column_type;
            $custom_view_filter->view_column_target = $custom_view_column->view_column_target;
            $custom_view_filter->view_group_condition = $custom_view_column->view_group_condition;
            $custom_view_filter->view_filter_condition = FilterOption::EQ;
            $custom_view_filter->view_filter_condition_value_text = $value;
            if ($column_item->isMultipleEnabled()) {
                $custom_view_filter->is_multiple = true;
            }
            $filters[] = $custom_view_filter;
            if ($custom_view_filter->view_column_target_id == SystemColumn::WORKFLOW_STATUS()->option()['id']) {
                System::setRequestSession(Define::SYSTEM_KEY_SESSION_WORLFLOW_STATUS_CHECK, true);
            }
            if ($custom_view_filter->view_column_target_id == SystemColumn::WORKFLOW_WORK_USERS()->option()['id']) {
                System::setRequestSession(Define::SYSTEM_KEY_SESSION_WORLFLOW_FILTER_CHECK, true);
            }
        }
        $filter_func = function ($model) use ($filters, $group_view) {
            $filter_raws = [];
            foreach ($filters as $filter) {
                if (isset($filter->view_group_condition) || $filter->is_multiple) {
                    $filter_raws[] = $filter;
                } else {
                    $group_view->custom_view_filters->push($filter);
                }
            }
            $group_view->filterModel($model);
            foreach ($filter_raws as $filter_raw) {
                $column_item = $filter_raw->column_item;
                $value_table_column = $column_item->getTableColumn();
                $query_value = $column_item->convertFilterValue($filter_raw->view_filter_condition_value_text);
                if (is_nullorempty($query_value)) {
                    if ($filter_raw->is_multiple) {
                        $model->where(function($query) use($value_table_column) {
                            $query->whereNull($value_table_column)->orWhere($value_table_column, '[]');
                        });
                    } else {
                        $model->whereNull($value_table_column);
                    }
                } else {
                    if ($filter_raw->is_multiple) {
                        $column = \DB::getQueryGrammar()->wrapJsonExtract($value_table_column);
                        $model->whereRaw("$column = ?", [$filter_raw->view_filter_condition_value_text]);
                    } else {
                        $column = \DB::getQueryGrammar()->getDateFormatString($filter_raw->view_group_condition, $value_table_column);
                        $model->whereRaw("$column = ?", [$query_value]);
                    }
                }
            }
            return $model;
        };
        return $filter_func;
    }


    /**
     * Open a part of the form the user has to ask for by name. Every field
     * added between this call and closeFoldSection() sits inside the block.
     *
     * The fields keep their name and stay in the request: a block is hidden
     * with css only. Hiding with the data-filter attribute would disable them
     * instead, and a disabled field is not posted - saving the form would wipe
     * everything the user could not see.
     *
     * @param mixed $form
     * @param string $title text on the line that opens the block
     * @param bool $opened open it from the start
     * @return void
     */
    protected static function openFoldSection(&$form, string $title, bool $opened = false)
    {
        static::setFoldSectionScript();

        $caret = $opened ? 'fa-caret-down' : 'fa-caret-right';
        $style = $opened ? '' : ' style="display:none;"';

        $form->html(
            '<div class="form-group box-header with-border exment-fold">'
            . '<div class="row"><div class="col-sm-12" style="margin:0 70px;">'
            . '<a href="javascript:void(0);" class="exment-fold-toggle" style="font-size:15px;font-weight:bold;text-decoration:none;">'
            . '<i class="fa ' . $caret . ' exment-fold-caret" style="display:inline-block;width:14px;"></i>&nbsp;'
            . esc_html($title)
            . '</a></div></div></div>'
            . '<div class="exment-fold-body"' . $style . '>'
        )->plain();
    }

    /**
     * Close the block opened by openFoldSection().
     *
     * @param mixed $form
     * @return void
     */
    protected static function closeFoldSection(&$form)
    {
        $form->html('</div>')->plain();
    }

    /**
     * Client side of openFoldSection(). Bound to the document once, so it
     * survives a pjax screen change and is not bound twice by a second block.
     *
     * @return void
     */
    protected static function setFoldSectionScript()
    {
        Admin::script(<<<'SCRIPT'
if (!window.exmentFoldBound) {
    window.exmentFoldBound = true;
    $(document).on('click', '.exment-fold-toggle', function (e) {
        e.preventDefault();
        var $body = $(this).closest('.exment-fold').next('.exment-fold-body');
        var opened = $body.is(':visible');
        $body.toggle(!opened);
        $(this).find('.exment-fold-caret')
            .toggleClass('fa-caret-right', opened)
            .toggleClass('fa-caret-down', !opened);
    });
    // A required field inside a closed block stops the submit with nothing on
    // screen to explain it, so open the block that holds it. "invalid" does not
    // bubble, which is why this listens in the capture phase.
    document.addEventListener('invalid', function (e) {
        var body = (e.target && e.target.closest) ? e.target.closest('.exment-fold-body') : null;
        if (body && $(body).is(':hidden')) {
            $(body).show().prev('.exment-fold').find('.exment-fold-caret')
                .removeClass('fa-caret-right').addClass('fa-caret-down');
        }
    }, true);
}
SCRIPT);
    }

    // @phpstan-ignore-next-line
    protected static function setViewInfoboxFields(&$form)
    {
        // view input area ----------------------------------------------------
        $form->switchbool('use_view_infobox', exmtrans("custom_view.use_view_infobox"))
            ->help(exmtrans("custom_view.help.use_view_infobox"))
            ->default(false)
            ->attribute(['data-filtertrigger' =>true]);

        $form->text('view_infobox_title', exmtrans("custom_view.view_infobox_title"))
            ->help(exmtrans("custom_view.help.view_infobox_title"))
            ->attribute(['data-filter' => json_encode(['key' => 'use_view_infobox', 'value' => '1'])]);

        $form->tinymce('view_infobox', exmtrans("custom_view.view_infobox"))
            ->help(exmtrans("custom_view.help.view_infobox"))
            ->disableImage()
            ->attribute(['data-filter' => json_encode(['key' => 'use_view_infobox', 'value' => '1'])]);
    }

    // @phpstan-ignore-next-line
    protected static function convertGroups($targetOptions, $defaultCustomTable)
    {
        $options = collect($targetOptions)->mapToDictionary(function ($item, $query) {
            $keys = preg_split('/\?/', $query, 2);
            $items = preg_split('/\:/', $item);
            // @phpstan-ignore-next-line
            return [$keys[1] => [$query => trim($items[count($items)-1])]];
        })->map(function ($item, $key) use ($defaultCustomTable) {
            if (empty($key)) {
                $label = $defaultCustomTable->table_view_name;
            } else {
                parse_str($key, $view_column_query_array);
                $column_table_id = array_get($view_column_query_array, 'table_id', $defaultCustomTable->id ?? null);
                $view_pivot_column_id = array_get($view_column_query_array, 'view_pivot_column_id');
                $view_pivot_table_id = array_get($view_column_query_array, 'view_pivot_table_id');
                $label = CustomTable::getEloquent($column_table_id)->table_view_name;
                if (isset($view_pivot_column_id) && !is_nullorempty($view_pivot_column = CustomColumn::getEloquent($view_pivot_column_id))) {
                    $label .= ' : ' . $view_pivot_column->column_view_name;
                }
            }
            return [
                'label' => $label,
                'options' => call_user_func_array("array_merge", $item)
            ];
        })->toArray();
        return $options;
    }

    /**
     * Add to a column list whatever the rows of this view already point at
     * and the list can no longer offer.
     *
     * Each of these lists is narrowed - a filter and a sort take indexed
     * columns only, a sort leaves out the multi-value ones - so what may be
     * offered can change after a row was written. Turning off the search
     * setting of one column is enough. The row itself goes on working, the
     * grid still filters and sorts by it, but the form cannot show it any
     * more; and since the target is required, one such row stops the whole
     * setting screen from saving, with nothing on screen to say which row or
     * why.
     *
     * Kept rather than quietly dropped: the row still does its job, the
     * column may be given its index back tomorrow, and throwing away a filter
     * the user never asked to remove is the worse mistake.
     *
     * @param array<string, string> $targetOptions list the form can offer
     * @param CustomView|null $custom_view view being edited or copied from
     * @param string $relation_name custom_view_columns, custom_view_filters...
     * @return array<string, string>
     */
    protected static function appendStoredTargetOptions(array $targetOptions, $custom_view, string $relation_name): array
    {
        if (!isset($custom_view) || !isset($custom_view->id)) {
            return $targetOptions;
        }

        foreach ($custom_view->{$relation_name} as $row) {
            $target = strval($row->view_column_target);
            if ($target === '' || array_key_exists($target, $targetOptions)) {
                continue;
            }

            // A row whose column is gone cannot name itself, so the stored
            // key is all there is to show.
            $column_item = ($row->view_column_type == Enums\ConditionType::COLUMN && !isset($row->custom_column))
                ? null : $row->column_item;
            $label = isset($column_item) ? $column_item->label() : null;

            $targetOptions[$target] = sprintf(
                exmtrans('custom_view.column_target_unavailable'),
                !is_nullorempty($label) ? $label : $target
            );
        }

        return $targetOptions;
    }

    /**
     * Set filter fileds form
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @param boolean $is_aggregate
     * @param CustomView|null $custom_view
     * @return void
     */
    public static function setFilterFields(&$form, $custom_table, $is_aggregate = false, $custom_view = null)
    {
        $manualUrl = getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'));
        $targetOptions = static::appendStoredTargetOptions($custom_table->getColumnsSelectOptions(
            [
                'append_table' => true,
                'index_enabled_only' => true,
                'include_parent' => true,
                'include_child' => $is_aggregate,
                'include_workflow' => true,
                'include_workflow_work_users' => true,
                'ignore_attachment' => true,
                'ignore_many_to_many' => true,
                'ignore_multiple_refer' => true,
            ]
        ), $custom_view, 'custom_view_filters');
        if (boolval(config('exment.form_column_option_group', false))) {
            $targetGroups = static::convertGroups($targetOptions, $custom_table);
        }

        // filter setting
        $hasManyTable = new ConditionHasManyTable($form, [
            'ajax' => admin_url("webapi/{$custom_table->table_name}/filter-value"),
            'name' => "custom_view_filters",
            'linkage' => json_encode(['view_filter_condition' => admin_urls('view', $custom_table->table_name, 'filter-condition')]),
            'targetOptions' => $targetOptions,
            'targetGroups' => $targetGroups ?? null,
            'custom_table' => $custom_table,
            'filterKind' => Enums\FilterKind::VIEW,
            'condition_target_name' => 'view_column_target',
            'condition_key_name' => 'view_filter_condition',
            'condition_value_name' => 'view_filter_condition_value',
        ]);

        $hasManyTable->callbackField(function ($field) use ($manualUrl) {
            $field->descriptionHtml(sprintf(exmtrans("custom_view.description_custom_view_filters"), $manualUrl));
        });

        $hasManyTable->render();

        $form->radio('condition_join', exmtrans("condition.condition_join"))
            ->options(exmtrans("condition.condition_join_options"))
            ->default('and');
    }


    /**
     * Set column fields form
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @param array<string, mixed> $column_options
     * @param CustomView|null $custom_view
     * @return void
     */
    // @phpstan-ignore-next-line
    public static function setColumnFields(&$form, $custom_table, array $column_options = [], $custom_view = null)
    {
        // columns setting
        $column_options = array_merge([
            'append_table' => true,
            'include_parent' => true,
            'include_workflow' => true,
        ], $column_options);

        $form->hasManyTable('custom_view_columns', exmtrans("custom_view.custom_view_columns"), function ($form) use ($custom_table, $column_options, $custom_view) {
            $targetOptions = static::appendStoredTargetOptions(
                $custom_table->getColumnsSelectOptions($column_options),
                $custom_view,
                'custom_view_columns'
            );

            $field = $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                ->options($targetOptions);

            if (boolval(config('exment.form_column_option_group', false))) {
                $targetGroups = static::convertGroups($targetOptions, $custom_table);
                $field->groups($targetGroups);
            }

            $form->text('view_column_name', exmtrans("custom_view.view_column_name"));

            // Appearance picked here beats whatever the column setting says:
            // a view exists to show the same table another way, and the
            // person building it cannot be expected to go and edit columns
            // that other views are using too.
            $form->select('grid_preset', exmtrans("custom_view.grid_preset"))
                ->options(CellStylePreset::getPickerOptions())
                ->attribute([
                    'data-cellstyle-preset' => $custom_table->table_name,
                    'data-cellstyle-preset-label' => exmtrans('cell_style_preset.edit_preset'),
                    'data-cellstyle-preset-placeholder' => exmtrans('cell_style_preset.select_placeholder'),
                    'data-cellstyle-types' => json_encode(static::getColumnTypeMap($targetOptions)),
                ])
                ->help(exmtrans("custom_view.help.grid_preset"));

            $form->hidden('order')->default(0);
        })->required()->setTableColumnWidth(5, 2, 3, 2)
        ->rowUpDown('order', 10)
        ->descriptionHtml(exmtrans("custom_view.description_custom_view_columns"));
    }


    /**
     * The column type behind each entry of a column target list.
     *
     * The preset list is narrowed to the presets that fit the column - an
     * avatar is not something a date can be drawn as - and on a view form
     * the column is whatever the row points at. Sent along as a map so the
     * browser can answer that again every time a row is pointed elsewhere,
     * without asking the server.
     *
     * A target with no column of its own is left out and the browser offers it
     * the whole library rather than nothing - except the system columns that
     * do say what they hold. A created date is a date whichever table it sits
     * on, and offering it an avatar is offering something that cannot be drawn.
     * The workflow status stays out: it is a status, but not of a type any
     * preset is written against, and matching on it would leave the row with
     * no choice at all.
     *
     * @param array<string, mixed> $targetOptions
     * @return array<string, string>
     */
    protected static function getColumnTypeMap(array $targetOptions): array
    {
        $types = [];

        foreach ($targetOptions as $key => $label) {
            // "822?table_id=14" for a column - of this table or of a parent
            // one - and a name for everything else.
            $column_key = explode('?', strval($key))[0];

            if (!is_numeric($column_key)) {
                $system_type = array_get(SystemColumn::getOption(['name' => $column_key]), 'type');
                if (in_array($system_type, [ColumnType::DATETIME, ColumnType::USER], true)) {
                    $types[strval($key)] = strval($system_type);
                }
                continue;
            }

            $custom_column = CustomColumn::getEloquent($column_key);
            if (isset($custom_column)) {
                $types[strval($key)] = strval($custom_column->column_type);
            }
        }

        return $types;
    }


    /**
     * Set sort fileds form
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @param boolean $include_parent
     * @param CustomView|null $custom_view
     * @return void
     */
    public static function setSortFields(&$form, $custom_table, $include_parent = false, $custom_view = null)
    {
        $manualUrl = getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'));

        // sort setting
        $form->hasManyTable('custom_view_sorts', exmtrans("custom_view.custom_view_sorts"), function ($form) use ($custom_table, $include_parent, $custom_view) {
            $targetOptions = static::appendStoredTargetOptions($custom_table->getColumnsSelectOptions([
                'append_table' => true,
                'index_enabled_only' => true,
                'include_parent' => $include_parent,
                'ignore_multiple' => true,
                'ignore_many_to_many' => true,
            ]), $custom_view, 'custom_view_sorts');

            $field = $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                ->options($targetOptions);

            if (boolval(config('exment.form_column_option_group', false))) {
                $targetGroups = static::convertGroups($targetOptions, $custom_table);
                $field->groups($targetGroups);
            }

            $form->select('sort', exmtrans("custom_view.sort"))->options(Enums\ViewColumnSort::transKeyArray('custom_view.column_sort_options'))
                ->required()
                ->default(1)
                ->help(exmtrans('custom_view.help.sort_type'));
            $form->hidden('priority')->default(0);
        })->setTableColumnWidth(7, 3, 2)
        ->rowUpDown('priority')
        ->descriptionHtml(sprintf(exmtrans("custom_view.description_custom_view_sorts"), $manualUrl));
    }

    /**
     * setTableMenuButton
     *
     * @param bool $grid_tool render as a data-grid toolbar button (normal flow,
     *                        .exm-grid-tool spacing) instead of a right float
     * @return void
     */
    // @phpstan-ignore-next-line
    protected function setTableMenuButton(&$tools, bool $grid_tool = false)
    {
        if ($this->custom_table->enableTableMenuButton()) {
            $button = new Tools\CustomTableMenuButton('data', $this->custom_table);
            $tools[] = \Exment::getRender($grid_tool ? $button->gridTool() : $button);
        }
    }

    /**
     * setViewMenuButton
     *
     * @param bool $grid_tool see setTableMenuButton
     * @return void
     */
    // @phpstan-ignore-next-line
    protected function setViewMenuButton(&$tools, bool $grid_tool = false)
    {
        if ($this->custom_table->enableViewMenuButton()) {
            $button = new Tools\CustomViewMenuButton($this->custom_table, $this->custom_view);
            $tools[] = \Exment::getRender($grid_tool ? $button->gridTool() : $button);
        }
    }

    /**
     * setNewButton
     *
     * @param bool $grid_tool see setTableMenuButton
     * @return void
     */
    // @phpstan-ignore-next-line
    protected function setNewButton(&$tools, bool $grid_tool = false)
    {
        if ($this->preview) {
            return;
        }

        if ($this->custom_table->enableCreate(true) === true) {
            $tools[] = \Exment::getRender(view('exment::custom-value.new-button', [
                'table_name' => $this->custom_table->table_name,
                'grid_tool' => $grid_tool,
            ]));
        }
    }

    /**
     * Import and export button, the same one the data grid shows.
     *
     * @param Grid $grid grid the export urls are read from
     * @param bool $grid_tool see setTableMenuButton
     * @return void
     */
    // @phpstan-ignore-next-line
    protected function setExportImportButton(&$tools, $grid, bool $grid_tool = false)
    {
        if ($this->preview) {
            return;
        }

        $import = $this->custom_table->enableImport();
        $export = $this->custom_table->enableExport();
        if ($import !== true && $export !== true) {
            return;
        }

        // plugin export stays off outside the data grid, as on the other views
        $button = (new Tools\ExportImportButton(
            admin_urls('data', $this->custom_table->table_name),
            $grid,
            $export === true,
            $export === true,
            $import === true
        ))->setCustomTable($this->custom_table);

        $tools[] = \Exment::getRender($grid_tool ? $button->gridTool() : $button);
    }

    /**
     * Grid used by the export flow of a view that draws its own page instead of
     * a table (board, calendar). The export urls and the exporter both need a
     * grid, but this one never renders a table: on an export request it only
     * runs the exporter, which sends the file and exits.
     *
     * @return Grid
     */
    // @phpstan-ignore-next-line
    protected function getExportGrid()
    {
        $classname = getModelName($this->custom_table);
        $grid = new Grid(new $classname());
        // export what the view shows, filtered and sorted the same way. The
        // reset matters: this is a second query off the same view, and the
        // search service would otherwise skip a join it has already made.
        $this->custom_view->resetSearchService();
        $this->custom_view->filterSortModel($grid->model());
        $grid->exporter($this->getImportExportService($grid));

        return $grid;
    }

    /**
     * @param Request $request
     */
    // @phpstan-ignore-next-line
    public function import(Request $request)
    {
        $service = $this->getImportExportService()
            ->format($request->file('custom_table_file'))
            ->filebasename($this->custom_table->table_name);
        $result = $service->import($request);

        return getAjaxResponse($result);
    }

    /**
     * Import and export service of this table. Every view kind shares it, so the
     * import modal keeps working whichever view it was opened from.
     */
    // @phpstan-ignore-next-line
    public function getImportExportService($grid = null)
    {
        $service = (new DataImportExport\DataImportExportService())
            ->exportAction(new DataImportExport\Actions\Export\CustomTableAction(
                [
                    'custom_table' => $this->custom_table,
                    'grid' => $grid,
                ]
            ))->viewExportAction(new DataImportExport\Actions\Export\ViewAction(
                [
                    'custom_table' => $this->custom_table,
                    'custom_view' => $this->custom_view,
                    'grid' => $grid,
                ]
            ))->pluginExportAction(new DataImportExport\Actions\Export\PluginAction(
                [
                    'custom_table' => $this->custom_table,
                    'custom_view' => $this->custom_view,
                    'grid' => $grid,
                ]
            ))->importAction(new DataImportExport\Actions\Import\CustomTableAction(
                [
                    'custom_table' => $this->custom_table,
                    'primary_key' => app('request')->input('select_primary_key') ?? null,
                ]
            ));
        return $service;
    }


    // @phpstan-ignore-next-line
    /**
     * When set, this grid is embedded inside another screen (the project
     * portal) and must show only the records of one parent record. The
     * host screen also brings its own toolbar, so the grid drops its own.
     */
    protected $embed_parent_type = null;
    protected $embed_parent_id = null;

    // @phpstan-ignore-next-line
    public function setEmbedRelation($parent_type, $parent_id)
    {
        $this->embed_parent_type = $parent_type;
        $this->embed_parent_id = $parent_id;

        return $this;
    }

    public function isEmbed(): bool
    {
        return isset($this->embed_parent_type);
    }

    /**
     * Name of a javascript function the host screen provides for editing one
     * record. A board drawn on a screen that has an editor of its own - the
     * project portal - calls it instead of opening the admin's own form, so
     * the reader stays in the screen they were already in.
     *
     * @var string|null
     */
    protected $editor_hook = null;

    /**
     * @param string $function_name global javascript function, called with
     *                              (table_name, id)
     * @return $this
     */
    public function setEditorHook(string $function_name)
    {
        $this->editor_hook = $function_name;

        return $this;
    }

    /**
     * @return string
     */
    public function getEditorHook(): string
    {
        return strval($this->editor_hook);
    }

    /**
     * Narrow a query to the parent record this grid is embedded under.
     * parent_type / parent_id are the 1:N relation columns every child
     * value table has, so this works for any related table.
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @return mixed
     */
    protected function applyEmbedFilter($query)
    {
        if ($this->isEmbed()) {
            $query->where('parent_type', $this->embed_parent_type)
                ->where('parent_id', $this->embed_parent_id);
        }

        return $query;
    }

    abstract public function grid();
}
