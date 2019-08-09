<?php
namespace Exceedone\Exment\Services\Plugin;

use App\Http\Controllers\Controller;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use BadMethodCallException;
use Response;

class PluginPageController extends Controller
{
    protected $pluginPage;
    protected $plugin;
    
    public function __construct(PluginPageBase $pluginPage)
    {
        $this->pluginPage = $pluginPage;
        $this->plugin = isset($pluginPage) ? $pluginPage->_plugin() : null ;
    }

    /**
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     *
     */
    public function __call($method, $parameters)
    {
        if(!$this->pluginPage){
            abort(404);
        }

        if(!method_exists($this->pluginPage, $method)){
            throw new BadMethodCallException(sprintf(
                'Method %s::%s does not exist.', static::class, $method
            ));
        }

        // create html
        $html = call_user_func_array([$this->pluginPage, $method], $parameters);

        $content = new Content;
        return $content
            ->header($this->plugin->plugin_view_name)
            ->headericon($this->plugin->getOption('icon'))
            ->row($html);
    }

    public function _readPublicFile(Request $request, $cssfile){
        // get file path
        $path = trim($request->getPathInfo(), '/');
        $path = ltrim($path, $this->plugin->getRouteUri());
        $path = trim($path, '/');

        $extension = pathinfo($path)['extension'];
        $mineType = $extension == 'css' ? 'css' : 'javascript';
        
        // get base path
        $base_path = $this->plugin->getFullPath();
        $filePath = path_join($base_path, 'public', $path);

        // if not exists, return 404
        if(!\File::exists($filePath)){
            abort(404);
        }

        $file = \File::get($filePath);
        // create response
        $response = Response::make($file, 200);
        $response->header("Content-Type", "text/$mineType");

        return $response;
    }
}
