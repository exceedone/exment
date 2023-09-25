<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Validator\DuplicateRelationRule;

class CustomRelationController extends AdminControllerTableBase
{
    use HasResourceTableActions;

    public function __construct(?CustomTable $custom_table, Request $request)
    {
        parent::__construct($custom_table, $request);

        $title = exmtrans("custom_relation.header") . ' : ' . ($custom_table ? $custom_table->table_view_name : null);
        $this->setPageInfo($title, $title, exmtrans("custom_relation.description"), 'fa-compress');
    }

    /**
     * Index interface.
     *
     * @param Request $request
     * @param Content $content
     * @return Content|void
     */
    public function index(Request $request, Content $content)
    {
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::CUSTOM_TABLE)) {
            return;
        }
        return parent::index($request, $content);
    }

    /**
     * Edit
     *
     * @param Request $request
     * @param Content $content
     * @param $tableKey
     * @param $id
     * @return Content|void
     */
    public function edit(Request $request, Content $content, $tableKey, $id)
    {
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::CUSTOM_TABLE)) {
            return;
        }
        if (!$this->validateTableAndId(CustomRelation::class, $id, 'relation')) {
            return;
        }
        return parent::edit($request, $content, $tableKey, $id);
    }

    /**
     * Create interface.
     *
     * @param Request $request
     * @param Content $content
     * @return Content|void
     */
    public function create(Request $request, Content $content)
    {
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::CUSTOM_TABLE)) {
            return;
        }
        return parent::create($request, $content);
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new CustomRelation());
        $grid->column('parent_custom_table.table_view_name', exmtrans("custom_relation.parent_custom_table"))->sortable();
        $grid->column('child_custom_table.table_view_name', exmtrans("custom_relation.child_custom_table"))->sortable();
        $grid->column('relation_type', exmtrans("custom_relation.relation_type"))->sortable()->display(function ($relation_type) {
            return RelationType::getEnum($relation_type)->transKey('custom_relation.relation_type_options') ?? null;
        });

        if (isset($this->custom_table)) {
            $grid->model()->where('parent_custom_table_id', $this->custom_table->id);
        }

        $grid->tools(function (Grid\Tools $tools) {
            $tools->append(new Tools\CustomTableMenuButton('relation', $this->custom_table));
        });

        $grid->disableExport();
        $grid->actions(function ($actions) {
            $actions->disableView();
        });

        // filter
        $grid->filter(function ($filter) {
            // Remove the default id filter
            $filter->disableIdFilter();

            // Add a column filter
            $filter->equal('child_custom_table', exmtrans("custom_relation.child_custom_table"))->select(function ($val) {
                return CustomTable::filterList()->pluck('table_view_name', 'id')->toArray();
            });
            $filter->equal('relation_type', exmtrans("custom_relation.relation_type"))->select(function ($val) {
                return RelationType::transKeyArray('custom_relation.relation_type_options');
            });
        });
        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        $form = new Form(new CustomRelation());
        $form->internal('parent_custom_table_id')->default($this->custom_table->id);

        $form->descriptionHtml(sprintf(exmtrans('custom_relation.help.relation_caution'), getManualUrl('relation')));

        $form->display('parent_custom_table.table_view_name', exmtrans("custom_relation.parent_custom_table"))->default($this->custom_table->table_view_name);

        $custom_table = $this->custom_table;
        $custom_table_id = $this->custom_table->id;

        if (isset($id)) {
            $custom_relation = CustomRelation::find($id);
            $child_table = $custom_relation->child_custom_table_cache;
            $relation_type = $custom_relation->relation_type;
            $form->display('child_custom_table_id', exmtrans("custom_relation.child_custom_table"))
                ->displayText($child_table->table_view_name);
            $form->display('relation_type', exmtrans("custom_relation.relation_type"))
                ->displayText(function ($val) use ($relation_type) {
                    $relation_type = RelationType::getEnum($val?? $relation_type);
                    return $relation_type->transKey('custom_relation.relation_type_options');
                });
            $form->hidden('child_custom_table_id')->default($child_table->id);
            $form->hidden('relation_type')->default($relation_type);
        } else {
            $validates = ["loopRelation:{$custom_table_id},{$id}"];
            $validates[] = new DuplicateRelationRule($id);

            $form->select('child_custom_table_id', exmtrans("custom_relation.child_custom_table"))->options(function ($child_custom_table_id) use ($custom_table_id) {
                return CustomTable::filterList()
                    ->where('id', '<>', $custom_table_id)
                    ->pluck('table_view_name', 'id')
                    ->toArray();
            })
            ->required()
            ->rules($validates);

            $relation_type_options = RelationType::transKeyArray("custom_relation.relation_type_options");
            $form->select('relation_type', exmtrans("custom_relation.relation_type"))
                ->options($relation_type_options)
                ->required()
                ->attribute(['data-filtertrigger' =>true]);
        }

        $form->embeds('options', exmtrans("custom_column.options.header"), function ($form) use ($custom_table) {
            $manual_url = getManualUrl('data_import_export?id='.exmtrans('custom_column.help.select_import_column_id_key'));
            $form->select('parent_import_column_id', exmtrans("custom_relation.parent_import_column_id"))
                ->help(exmtrans("custom_relation.help.parent_import_column_id", $manual_url))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'relation_type', 'value' => [RelationType::ONE_TO_MANY]])])
                ->options(function ($select_table, $form) use ($custom_table) {
                    return CustomTable::getEloquent($custom_table)->getColumnsSelectOptions([
                        'append_table' => false,
                        'include_system' => false
                    ]) ?? [];
                });

            $form->select('parent_export_column_id', exmtrans("custom_relation.parent_export_column_id"))
                ->help(exmtrans("custom_relation.help.parent_export_column_id", $manual_url))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'relation_type', 'value' => [RelationType::ONE_TO_MANY]])])
                ->options(function ($select_table, $form) use ($custom_table) {
                    return CustomTable::getEloquent($custom_table)->getColumnsSelectOptions([
                        'append_table' => false,
                        'include_system' => false
                    ]) ?? [];
                });
        })->disableHeader();

        $custom_table = $this->custom_table;
        $form->tools(function (Form\Tools $tools) use ($custom_table) {
            $tools->add(new Tools\CustomTableMenuButton('relation', $custom_table));
        });
        return $form;
    }
}
