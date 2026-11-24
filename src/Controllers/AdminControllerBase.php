<?php

namespace Exceedone\Exment\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use ExmentAdminCore\Admin\Layout\Content;

/**
 * Admin(Exment) Controller
 *
* @method \ExmentAdminCore\Admin\Grid grid()
* @method \ExmentAdminCore\Admin\Form form($id = null)
 */
class AdminControllerBase extends Controller implements \Illuminate\Routing\Controllers\HasMiddleware
{
    use ExmentControllerTrait;

    /**
     * No middleware of the controller itself (Exment's are the groups of the routes).
     *
     * Declared so that Laravel makes the controller when the action runs, after the middleware,
     * and not before them to ask it for its middleware (Controller::getMiddleware() of the host
     * app's base controller). The constructors set the title and the header of the page with
     * exmtrans(): made before the middleware, they were in the language of the site, not in the
     * language of the user (UserLocaleTimezone runs after the login is known).
     *
     * @return array<int, mixed>
     */
    public static function middleware(): array
    {
        return [];
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        return $this->AdminContent($content)->body($this->grid());
    }

    /**
     * Show interface.
     *
     * @param Request $request
     * @param Content $content
     * @param mixed $id
     * @return Content|\Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function show(Request $request, Content $content, $id)
    {
        if (method_exists($this, 'detail')) {
            $render = $this->detail($id);
        } else {
            $url = url_join($request->url(), 'edit');
            return redirect($url);
        }
        return $this->AdminContent($content)->body($render);
    }

    /**
     * Edit interface.
     *
     * @param mixed   $id
     * @param Content $content
     * @return Content
     */
    public function edit(Request $request, Content $content, $id)
    {
        return $this->AdminContent($content)->body($this->form($id)->edit($id));
    }

    /**
     * Create interface.
     *
     * @param Content $content
     * @return Content
     */
    public function create(Request $request, Content $content)
    {
        return $this->AdminContent($content)->body($this->form());
    }
}
