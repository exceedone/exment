<?php

namespace Exceedone\Exment\Services\Studio;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\Plugin;
use Illuminate\Support\Collection;

/**
 * Studio, phase 1: every screen of the site on one page.
 *
 * Exment builds a screen in three places today - the forms and the views of
 * each table, the dashboards, and the page plugins. This collects them, per
 * table like the apps of kintone, with the links to open, edit and copy them,
 * and the relations of the tables for the diagram (like the relationships
 * window of Access). It only reads: the screens are still made and changed
 * on their own setting pages.
 *
 * Only what the login user may set is listed: a table appears when the user
 * may change its table, forms or views, and on that table only the parts the
 * user may change.
 */
class ScreenList
{
    /**
     * The permissions that let a user set a screen of a table.
     */
    public const TABLE_PERMISSIONS = [Permission::CUSTOM_TABLE, Permission::CUSTOM_FORM, Permission::CUSTOM_VIEW];

    /**
     * @var Collection<int, CustomTable>|null
     */
    protected $tables;

    /**
     * The tables the login user may set a screen of, in the order of the table list.
     *
     * @return Collection<int, CustomTable>
     */
    public function tables(): Collection
    {
        if ($this->tables === null) {
            /** @var Collection<int, CustomTable> $tables */
            $tables = CustomTable::filterList(null, [
                'permissions' => static::TABLE_PERMISSIONS,
                'filter' => function ($model) {
                    return $model->orderBy('order')->orderBy('id');
                },
            ]);
            $this->tables = $tables->values();
        }
        return $this->tables;
    }

    /**
     * Whether the login user may open this page at all.
     */
    public function available(): bool
    {
        $user = \Exment::user();
        if (!isset($user)) {
            return false;
        }
        return $this->tables()->count() > 0 || $user->hasPermission(Permission::SYSTEM);
    }

    /**
     * One card per table: the table, its forms and its views.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tableCards(): array
    {
        $ids = $this->tables()->pluck('id')->all();
        $forms = CustomForm::whereIn('custom_table_id', $ids)->orderBy('id')->get()->groupBy('custom_table_id');
        $views = CustomView::whereIn('custom_table_id', $ids)
            ->where('view_type', ViewType::SYSTEM)
            ->orderBy('order')->orderBy('id')
            ->get()->groupBy('custom_table_id');

        $cards = [];
        foreach ($this->tables() as $table) {
            $name = $table->table_name;
            $canTable = $table->hasPermission(Permission::CUSTOM_TABLE);
            $canForm = $table->hasPermission(Permission::EDIT_CUSTOM_FORM);
            $canView = $table->hasSystemViewPermission();
            $canData = $table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE);
            // a table of one record opens its data, not a view: no view is made for it (#2269)
            $oneRecord = boolval($table->isOneRecord());

            $cards[] = [
                'id' => $table->id,
                'table_name' => $name,
                'table_view_name' => $table->table_view_name,
                'icon' => $table->getOption('icon') ?: 'fa-table',
                'color' => $table->getOption('color'),
                'data_url' => $canData ? admin_urls('data', $name) : null,
                'table_url' => $canTable ? admin_urls('table', $table->id, 'edit') : null,
                'column_url' => $canTable ? admin_urls('column', $name) : null,
                'forms' => $canForm ? $this->formRows($table, $forms->get($table->id, collect())) : null,
                'form_create_url' => $canForm ? admin_urls('form', $name, 'create') : null,
                'views' => $canView ? $this->viewRows($table, $views->get($table->id, collect()), $canData) : null,
                'view_create_url' => $canView && !$oneRecord ? admin_urls('view', $name, 'create') : null,
                'one_record' => $oneRecord,
            ];
        }
        return $cards;
    }

    /**
     * @param Collection<int, CustomForm> $forms
     * @return array<int, array<string, mixed>>
     */
    protected function formRows(CustomTable $table, Collection $forms): array
    {
        $name = $table->table_name;
        return $forms->map(function ($form) use ($name) {
            return [
                'name' => $form->form_view_name,
                'default' => boolval($form->default_flg),
                // changed in the builder of the Studio, like the form of a kintone app (ScreenEditor)
                'edit_url' => admin_urls('studio', 'screens', $form->id, 'edit'),
                'setting_url' => admin_urls('form', $name, $form->id, 'edit'),
                'copy_url' => admin_urls('form', $name, 'create?copy_id=' . $form->id),
            ];
        })->values()->all();
    }

    /**
     * @param Collection<int, CustomView> $views
     * @return array<int, array<string, mixed>>
     */
    protected function viewRows(CustomTable $table, Collection $views, bool $canData): array
    {
        $name = $table->table_name;
        $kinds = ViewKindType::transKeyArray('custom_view.custom_view_kind_type_options');
        return $views->map(function ($view) use ($name, $kinds, $canData) {
            $kind = strval($view->view_kind_type);
            return [
                'name' => $view->view_view_name,
                'kind' => array_get($kinds, $kind, ''),
                'default' => boolval($view->default_flg),
                'open_url' => $canData ? admin_urls('data', $name) . '?view=' . $view->suuid : null,
                'edit_url' => admin_urls('view', $name, $view->id, 'edit'),
                // the view made for all data is not copied: a table has only one of it
                'copy_url' => $kind === ViewKindType::ALLDATA ? null : admin_urls('view', $name, 'create?copy_id=' . $view->id),
            ];
        })->values()->all();
    }

    /**
     * The dashboards the login user sees, with the edit link when the user may change one.
     *
     * @return array<int, array<string, mixed>>
     */
    public function dashboards(): array
    {
        return Dashboard::get()->map(function ($dashboard) {
            return [
                'name' => $dashboard->dashboard_view_name,
                'system' => $dashboard->dashboard_type == DashboardType::SYSTEM,
                'default' => boolval($dashboard->default_flg),
                'open_url' => admin_url('') . '?dashboard=' . $dashboard->suuid,
                'edit_url' => $dashboard->hasEditPermission() ? admin_urls('dashboard', $dashboard->id, 'edit') : null,
            ];
        })->values()->all();
    }

    /**
     * The pages of the page plugins. Listed for whom may set the plugins.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pluginPages(): array
    {
        $user = \Exment::user();
        if (!isset($user) || !$user->hasPermission(Permission::PLUGIN_ALL)) {
            return [];
        }

        return collect(Plugin::getPluginPages())->map(function ($page) {
            $plugin = $page->_plugin();
            return [
                'name' => $plugin->plugin_view_name,
                'open_url' => $plugin->getFullUrl(),
                'edit_url' => admin_urls('plugin', $plugin->id, 'edit'),
            ];
        })->values()->all();
    }

    /**
     * The relations among the listed tables: the parent and child tables
     * (one-to-many, many-to-many) and the columns that pick a row of another
     * table. The user and organization columns are left out - nearly every
     * table has one, and the lines would hide the rest.
     *
     * @return array<int, array{from: int, to: int, type: string, label: string}>
     */
    public function relations(): array
    {
        $ids = $this->tables()->pluck('id')->all();
        $edges = [];

        $relations = CustomRelation::whereIn('parent_custom_table_id', $ids)->whereIn('child_custom_table_id', $ids)->get();
        foreach ($relations as $relation) {
            $edges[] = [
                'from' => intval($relation->parent_custom_table_id),
                'to' => intval($relation->child_custom_table_id),
                'type' => $relation->relation_type == RelationType::MANY_TO_MANY ? 'many_to_many' : 'one_to_many',
                'label' => '',
            ];
        }

        $columns = CustomColumn::whereIn('custom_table_id', $ids)->where('column_type', ColumnType::SELECT_TABLE)->get();
        foreach ($columns as $column) {
            // the option keeps the id; the attribute also reads an old value kept as the table name
            $target = $column->select_target_table;
            if (!isset($target) || !in_array(intval($target->id), $ids, true)) {
                continue;
            }
            $edges[] = [
                // the column points from the table that has it to the table it picks from
                'from' => intval($column->custom_table_id),
                'to' => intval($target->id),
                'type' => 'select_table',
                'label' => strval($column->column_view_name),
            ];
        }
        return $edges;
    }
}
