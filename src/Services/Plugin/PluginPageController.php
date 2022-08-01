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

    public function __construct(?PluginPublicBase $pluginPage)
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
        $result = call_user_func_array([$this->pluginPage, $method], array_values($parameters));

        if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
            return $result;
        }

        $content = new Content();
        $content->row($result);
        if (method_exists($this->pluginPage, '_showHeader') && $this->pluginPage->_showHeader()) {
            $content->header($this->plugin->plugin_view_name)
            ->headericon($this->plugin->getOption('icon')?? 'fa-pencil');
        }

        return $content;
    }

    public function _readPublicFile(Request $request, ...$args)
    {
        // get file path
        $path = implode('/', $args);

        // get base path
        $base_path = $this->plugin->getFullPath();
        $filePath = path_join($base_path, 'public', $path);

        // if not exists, return 404
        if (!\File::exists($filePath)) {
            abort(404);
        }

        $file = \File::get($filePath);
        $extension = pathinfo($filePath)['extension'];

        switch ($extension) {
            case 'css':
                $mimeType = 'text/css';
                break;
            case 'js':
                $mimeType = 'text/javascript';
                break;
            default:
                $mimeType = \File::mimeType($filePath);
                break;
        }

        // create response
        $response = Response::make($file, 200);
        $response->header("Content-Type", $mimeType);

        return $response;
    }
}
