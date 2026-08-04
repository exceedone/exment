<?php

namespace Exceedone\Exment\Services\Plugin;

use App\Http\Controllers\Controller;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;
use BadMethodCallException;
use Response;

class PluginPageController extends Controller
{
    // @phpstan-ignore-next-line
    protected $pluginPage;
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
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
        // @phpstan-ignore-next-line
        $result = call_user_func_array([$this->pluginPage, $method], array_values($parameters));

        if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
            return $result;
        }

        $content = new Content();
        $content->row($result);
        // @phpstan-ignore-next-line
        if (method_exists($this->pluginPage, '_showHeader') && $this->pluginPage->_showHeader()) {
            $content->header($this->plugin->plugin_view_name)
            ->headericon($this->plugin->getOption('icon')?? 'fa-pencil');
        }

        return $content;
    }

    // @phpstan-ignore-next-line
    public function _readPublicFile(Request $request, ...$args)
    {
        // reject any traversal segments before joining
        foreach ($args as $arg) {
            if (!is_string($arg) || $arg === '' || strpos($arg, "\0") !== false
                || $arg === '..' || $arg === '.'
                || strpos($arg, '/') !== false || strpos($arg, '\\') !== false) {
                abort(404);
            }
        }

        // get file path
        $path = implode('/', $args);

        // get base path
        $base_path = $this->plugin->getFullPath();
        $publicDir = path_join($base_path, 'public');
        $filePath = path_join($publicDir, $path);

        // if not exists, return 404
        if (!\File::exists($filePath)) {
            abort(404);
        }

        // ensure resolved path is contained within the plugin's public directory
        $realFile = realpath($filePath);
        $realPublic = realpath($publicDir);
        if ($realFile === false || $realPublic === false
            || strpos($realFile, rtrim($realPublic, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
            abort(404);
        }

        $file = \File::get($realFile);
        $extension = pathinfo($realFile)['extension'] ?? '';

        switch ($extension) {
            case 'css':
                $mimeType = 'text/css';
                break;
            case 'js':
                $mimeType = 'text/javascript';
                break;
            default:
                $mimeType = \File::mimeType($realFile);
                break;
        }

        // create response
        $response = Response::make($file, 200);
        // @phpstan-ignore-next-line
        $response->header("Content-Type", $mimeType);

        return $response;
    }
}
