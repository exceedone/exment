<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Auth\Permission as Checker;
use ExmentAdminCore\Admin\Layout\Content;
use Exceedone\Exment\Services\Studio\RelationDiagram;
use Exceedone\Exment\Services\Studio\ScreenList;
use Illuminate\Http\Request;

/**
 * Studio: the screens of the site on one page (ISS00231, phase 1).
 *
 * The forms and views of each table, the dashboards and the pages of the
 * page plugins, with the links to open, edit and copy them, and the relation
 * diagram of the tables. Later phases make new screens here; this one lists
 * what the setting pages have made.
 */
class StudioController extends AdminControllerBase
{
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
        ]));
        return $content;
    }
}
