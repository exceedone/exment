<?php
namespace Exceedone\Exment\Services\Plugin;

use App\Http\Controllers\Controller;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use BadMethodCallException;
use Response;

class PluginCrudController extends Controller
{
    protected $pluginPage;
    protected $plugin;
    
    public function __construct(?PluginCrudBase $pluginPage)
    {
        $this->pluginPage = $pluginPage;
        $this->plugin = isset($pluginPage) ? $pluginPage->_plugin() : null ;
    }

    /**
     * Index. for grid.
     *
     * @return void
     */
    public function index()
    {
        $className = $this->pluginPage->gridClass;
        return (new $className($this->plugin, $this->pluginPage))->index();
    }

    /**
     * Show. for detail.
     *
     * @return void
     */
    public function show($id)
    {
        $className = $this->pluginPage->showClass;
        return (new $className($this->plugin, $this->pluginPage))->show($id);
    }

    /**
     * create. 
     *
     * @return void
     */
    public function create()
    {
        $className = $this->pluginPage->createClass;
        return (new $className($this->plugin, $this->pluginPage))->create();
    }


    /**
     * store. 
     *
     * @return void
     */
    public function store()
    {
        $className = $this->pluginPage->createClass;
        return (new $className($this->plugin, $this->pluginPage))->store();
    }


    /**
     * edit. 
     *
     * @return void
     */
    public function edit($id)
    {
        $className = $this->pluginPage->editClass;
        return (new $className($this->plugin, $this->pluginPage))->edit($id);
    }

    /**
     * update. 
     *
     * @return void
     */
    public function update($id)
    {
        $className = $this->pluginPage->editClass;
        return (new $className($this->plugin, $this->pluginPage))->update($id);
    }

    /**
     * destroy. 
     *
     * @return void
     */
    public function destroy($id)
    {
        $className = $this->pluginPage->deleteClass;
        $result = (new $className($this->plugin, $this->pluginPage))->delete($id);

        return getAjaxResponse([
            'status' => true,
            'message' => trans('admin.delete_succeeded'),
            'redirect' => $result,
        ]);
    }

    // /**
    //  * @param  string  $method
    //  * @param  array   $parameters
    //  * @return mixed
    //  *
    //  */
    // public function __call($method, $parameters)
    // {
    //     if (!$this->pluginPage) {
    //         abort(404);
    //     }

    //     if (!method_exists($this->pluginPage, $method)) {
    //         throw new BadMethodCallException(sprintf(
    //             'Method %s::%s does not exist.',
    //             static::class,
    //             $method
    //         ));
    //     }

    //     // create html
    //     $result = call_user_func_array([$this->pluginPage, $method], $parameters);

    //     return $result;
    // }
}