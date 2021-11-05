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
    public function index($endpoint)
    {
        $targetClass = $this->getClass($endpoint);

        $className = $targetClass->gridClass;
        return (new $className($this->plugin, $targetClass))->index();
    }

    /**
     * Show. for detail.
     *
     * @return void
     */
    public function show($endpoint, $id)
    {
        $targetClass = $this->getClass($endpoint);

        $className = $targetClass->showClass;
        return (new $className($this->plugin, $targetClass))->show($id);
    }

    /**
     * create. 
     *
     * @return void
     */
    public function create($endpoint)
    {
        $targetClass = $this->getClass($endpoint);

        $className = $targetClass->createClass;
        return (new $className($this->plugin, $targetClass))->create();
    }


    /**
     * store. 
     *
     * @return void
     */
    public function store($endpoint)
    {
        $targetClass = $this->getClass($endpoint);

        $className = $targetClass->createClass;
        return (new $className($this->plugin, $targetClass))->store();
    }


    /**
     * edit. 
     *
     * @return void
     */
    public function edit($endpoint, $id)
    {
        $targetClass = $this->getClass($endpoint);

        $className = $targetClass->editClass;
        return (new $className($this->plugin, $targetClass))->edit($id);
    }

    /**
     * update. 
     *
     * @return void
     */
    public function update($endpoint, $id)
    {
        $targetClass = $this->getClass($endpoint);

        $className = $targetClass->editClass;
        return (new $className($this->plugin, $targetClass))->update($id);
    }

    /**
     * destroy. 
     *
     * @return void
     */
    public function destroy($endpoint, $id)
    {
        $targetClass = $this->getClass($endpoint);

        $className = $targetClass->deleteClass;
        $result = (new $className($this->plugin, $targetClass))->delete($id);

        return getAjaxResponse([
            'status' => true,
            'message' => trans('admin.delete_succeeded'),
            'redirect' => $result,
        ]);
    }


    /**
     * Get plugin target class.
     * *If plugin supports multiple endpoint, get class using endpoint.*
     *
     * @param string|null $endpoint
     * @return PluginCrudBase
     */
    protected function getClass(?string $endpoint)
    {
        $className = $this->pluginPage->getPluginClassName($endpoint);
        if(!$className){
            abort(404);
        }
        $class = new $className($this->plugin);
        $class->setPluginOptions($this->pluginPage->getPluginOptions());

        return $class;
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