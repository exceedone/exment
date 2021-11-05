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
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     *
     */
    public function __call($method, $parameters)
    {
        if (!$this->pluginPage) {
            abort(404);
        }

        if (!method_exists($this->pluginPage, $method)) {
            throw new BadMethodCallException(sprintf(
                'Method %s::%s does not exist.',
                static::class,
                $method
            ));
        }

        // create html
        $result = call_user_func_array([$this->pluginPage, $method], $parameters);

        if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
            return $result;
        }
        if ($result instanceof Content) {
            return $result;
        }

        $content = new Content;
        $content->row($result);
        if (method_exists($this->pluginPage, '_showHeader') && $this->pluginPage->_showHeader()) {
            $content->header($this->plugin->plugin_view_name)
            ->headericon($this->plugin->getOption('icon'));
        }

        return $content;
    }
}