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
class AdminControllerBase extends Controller
{
    use ExmentControllerTrait;

    /**
     * The page in the language of the user.
     *
     * Laravel makes the controller before the middleware run (to ask it for its middleware), so a
     * constructor that sets the title and the header with exmtrans() reads them in the language of
     * the site. The language of the user is applied later (UserLocaleTimezone, after the login is
     * known): when it is another one, the constructor is run again before the action.
     *
     * @param string $method
     * @param array<mixed> $parameters
     * @return mixed
     */
    public function callAction($method, $parameters)
    {
        $this->localizePageInfo();
        return parent::callAction($method, $parameters);
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
