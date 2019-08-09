<?php
namespace Exceedone\Exment\Services\Plugin;

use App\Http\Controllers\Controller;
use Encore\Admin\Layout\Content;

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

        $html = call_user_func_array([$this->pluginPage, $method], $parameters);
        $content = new Content;
        return $content
            ->header($this->plugin->plugin_view_name)
            ->headericon($this->plugin->getOption('icon'))
            ->row($html);
    }
}
