<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Auth\Permission as Checker;
use ExmentAdminCore\Admin\Layout\Content;
use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Studio\BlankFormBuilder;
use Exceedone\Exment\Services\Studio\ExistingTableFormBuilder;
use Exceedone\Exment\Services\Studio\RelationDiagram;
use Exceedone\Exment\Services\Studio\ScreenList;
use Illuminate\Http\Request;

/**
 * Studio: the screens of the site in one place (ISS00231).
 *
 * Phase 1 lists the forms and views of each table, the dashboards and the
 * pages of the page plugins, with the relation diagram of the tables.
 * Phase 2 makes an input screen: from a blank page, where the items placed
 * on it become a new table and its columns, or from the columns of a table
 * that exists, which makes a new form of it and no table.
 */
class StudioController extends AdminControllerBase
{
    public const MODE_NEW = 'new';
    public const MODE_EXISTING = 'existing';

    public function __construct()
    {
        $this->setPageInfo(exmtrans('studio.header'), exmtrans('studio.header'), exmtrans('studio.description'), 'fa-th-large');
    }

    /**
     * @return Content|\Symfony\Component\HttpFoundation\Response
     */
    public function index(Request $request, Content $content)
    {
        $list = new ScreenList();
        if (!$list->available()) {
            Checker::error();
            return response('');
        }

        $tables = $list->tables()->map(function ($table) {
            return ['id' => intval($table->id), 'name' => strval($table->table_view_name)];
        })->all();

        $this->AdminContent($content);
        $content->row(view('exment::studio.index', [
            'tables' => $list->tableCards(),
            'dashboards' => $list->dashboards(),
            'plugin_pages' => $list->pluginPages(),
            'diagram' => RelationDiagram::layout($tables, $list->relations()),
            'diagram_box' => ['width' => RelationDiagram::BOX_WIDTH, 'height' => RelationDiagram::BOX_HEIGHT],
            'create_url' => $this->canBuild() ? admin_urls('studio', 'create') : null,
        ]));
        return $content;
    }

    /**
     * Whether the login user may make an input screen at all: a new table
     * (system permission of the tables), or a form of a table they may set.
     */
    protected function canBuild(): bool
    {
        return BlankFormBuilder::available() || ExistingTableFormBuilder::available();
    }

    /**
     * The builder of an input screen: for a new table, or for a table that exists.
     *
     * @return Content|\Symfony\Component\HttpFoundation\Response
     */
    public function create(Request $request, Content $content)
    {
        if (!$this->canBuild()) {
            Checker::error();
            return response('');
        }

        $canNew = BlankFormBuilder::available();
        $old = $request->old();
        $hasOld = is_array($old) && array_key_exists('fields', $old);
        $mode = $hasOld ? strval(array_get($old, 'mode')) : '';
        if (!in_array($mode, [static::MODE_NEW, static::MODE_EXISTING], true) || ($mode === static::MODE_NEW && !$canNew)) {
            $mode = $canNew ? static::MODE_NEW : static::MODE_EXISTING;
        }
        $screen = null;
        if ($hasOld) {
            $screen = $mode === static::MODE_NEW ? BlankFormBuilder::normalize($old) : ExistingTableFormBuilder::normalize($old);
        }

        $this->setPageInfo(exmtrans('studio.header'), exmtrans('studio.create_header'), exmtrans('studio.create_description'), 'fa-th-large');
        $this->AdminContent($content);
        $content->row(view('exment::studio.create', [
            'mode' => $mode,
            'can_new' => $canNew,
            'existing_tables' => ExistingTableFormBuilder::tables(),
            'palette' => BlankFormBuilder::palette(),
            'menu_parents' => Menu::where('menu_type', MenuType::PARENT_NODE)->orderBy('order')->get(['id', 'title'])->toArray(),
            'action' => admin_urls('studio'),
            'back_url' => admin_url('studio'),
            'screen' => $screen,
            'errors_by_key' => session('studio_errors', []),
            'max_fields' => BlankFormBuilder::MAX_FIELDS,
        ]));
        return $content;
    }

    /**
     * Make the built screen: a new table with its columns, form and view, or
     * a new form of a table that exists.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function store(Request $request)
    {
        $mode = strval($request->input('mode', static::MODE_NEW));
        if ($mode === static::MODE_EXISTING) {
            return $this->storeExisting($request);
        }

        if (!BlankFormBuilder::available()) {
            Checker::error();
            return response('');
        }

        $screen = BlankFormBuilder::normalize($request->only(['table_view_name', 'table_name', 'menu_parent_id', 'fields']));
        $errors = BlankFormBuilder::validate($screen);
        if (count($errors) > 0) {
            admin_toastr(exmtrans('studio.error.check_input'), 'error');
            return back()->withInput()->with('studio_errors', $errors);
        }

        $table = BlankFormBuilder::build($screen);

        admin_toastr(sprintf(exmtrans('studio.created'), $table->table_view_name));
        return redirect()->to(admin_urls('data', $table->table_name, 'create'));
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function storeExisting(Request $request)
    {
        if (!ExistingTableFormBuilder::available()) {
            Checker::error();
            return response('');
        }

        $screen = ExistingTableFormBuilder::normalize($request->only(['table_view_name', 'table_id', 'fields']));
        $errors = ExistingTableFormBuilder::validate($screen);
        if (count($errors) > 0) {
            admin_toastr(exmtrans('studio.error.check_input'), 'error');
            return back()->withInput()->with('studio_errors', $errors);
        }

        $form = ExistingTableFormBuilder::build($screen);
        $table = CustomTable::getEloquent($form->custom_table_id);

        admin_toastr(sprintf(exmtrans('studio.created_form'), $form->form_view_name, $table->table_view_name));
        return redirect()->to(admin_urls('data', $table->table_name, 'create?formid=' . $form->suuid));
    }
}
