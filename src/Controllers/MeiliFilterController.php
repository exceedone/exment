<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\MeiliFilterSetting;
use Exceedone\Exment\Services\Meili\FilterConfig;
use ExmentAdminCore\Admin\Grid;
use ExmentAdminCore\Admin\Form;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;

/**
 * Admin screen for columns used as filters (facets). Active in every
 * filter.mode - see MeiliFilterSetting. Save/delete -> auto reindex.
 */
class MeiliFilterController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct()
    {
        $this->setPageInfo(
            exmtrans('system.meili'),
            exmtrans('system.meili'),
            exmtrans('system.help.meili_global_search'),
            'fa-filter'
        );
    }

    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);
        $content->body($this->grid());
        return $content;
    }

    public function create(Request $request, Content $content)
    {
        $this->AdminContent($content);
        $content->body($this->form());
        return $content;
    }

    public function edit(Request $request, Content $content, $id)
    {
        $this->AdminContent($content);
        $content->body($this->form()->edit($id));
        return $content;
    }

    /**
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new MeiliFilterSetting());
        $grid->column('custom_table.table_view_name', exmtrans('custom_table.table_view_name'));
        $grid->column('column_name', exmtrans('custom_column.column_name'));
        $grid->column('view_label', exmtrans('custom_column.column_view_name'));
        $grid->column('alias', exmtrans('system.meili_filter_alias'));
        $grid->column('filter_type', exmtrans('system.meili_filter_type'));
        $grid->column('mode', exmtrans('system.meili_filter_include_mode'));
        $grid->column('order', exmtrans('custom_table.order'))->sortable();
        $grid->column('enabled', exmtrans('system.meili_enabled'))->bool();

        $grid->disableExport();
        $grid->disableColumnSelector();
        return $grid;
    }

    /**
     * $id is passed by HasResourceActions::update().
     *
     * @param int|string|null $id
     * @return Form
     */
    protected function form($id = null)
    {
        $form = new Form(new MeiliFilterSetting());

        $form->select('custom_table_id', exmtrans('custom_table.table_view_name'))
            ->required()
            ->options(CustomTable::filterList()->pluck('table_view_name', 'id'))
            ->load('column_name', admin_url('meili-filter/columns'));

        // Static options so the saved value still renders when editing; the ->load()
        // above replaces them with the chosen table's columns as soon as it fires.
        $form->select('column_name', exmtrans('custom_column.column_name'))
            ->required()
            ->options(\DB::table('custom_columns')->pluck('column_view_name', 'column_name')->toArray());

        $form->select('filter_type', exmtrans('system.meili_filter_type'))
            ->required()
            ->options(exmtrans('system.meili_filter_type_options'))
            ->default('equality');

        $form->select('mode', exmtrans('system.meili_filter_include_mode'))
            ->required()
            ->options(exmtrans('system.meili_filter_include_mode_options'))
            ->default('include')
            ->help(exmtrans('system.help.meili_filter_include_mode'));

        $form->text('view_label', exmtrans('custom_column.column_view_name'));
        // max:40 = column width (silently truncated otherwise); the charset must
        // exclude '::', which splitColumnPrefix() reads as a table qualifier.
        $form->text('alias', exmtrans('system.meili_filter_alias'))
            ->rules(['nullable', 'max:40', 'regex:/^[A-Za-z0-9_]+$/'])
            ->help(exmtrans('system.help.meili_filter_alias'));
        $form->number('order', exmtrans('custom_table.order'))->default(0);
        $form->switchbool('enabled', exmtrans('system.meili_enabled'))->default(1);

        return $form;
    }

    /**
     * AJAX: return the columns (filtered by valid type) of the selected table (dependent select).
     *
     * @return \Illuminate\Support\Collection<int,array{id:string,text:string}>
     */
    public function columnOptions(Request $request)
    {
        $id = $request->get('q');
        return collect(self::columnsForTable($id))
            ->map(fn ($text, $val) => ['id' => $val, 'text' => $text])
            ->values();
    }

    /**
     * [column_name => label] the filterable columns of a table (excludes text/auto_number...).
     *
     * @param \Exceedone\Exment\Model\CustomTable|string|int|null $customTableId
     * @return array<string,string>
     */
    public static function columnsForTable($customTableId): array
    {
        $table = CustomTable::getEloquent($customTableId);
        if (!$table) {
            return [];
        }

        $allowed = array_merge(
            FilterConfig::equalityTypes(),
            ['date', 'datetime', 'integer', 'currency', 'user', 'organization']
        );

        return collect($table->custom_columns)
            ->filter(fn ($c) => in_array((string) $c->column_type, $allowed, true))
            ->mapWithKeys(fn ($c) => [$c->column_name => $c->column_view_name . " ({$c->column_type})"])
            ->toArray();
    }
}
