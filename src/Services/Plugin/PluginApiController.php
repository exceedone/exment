<?php

namespace Exceedone\Exment\Services\Plugin;

use App\Http\Controllers\Controller;
use BadMethodCallException;

class PluginApiController extends Controller
{
    protected $pluginApi;

    public function __construct(?PluginApiBase $pluginApi)
    {
        $this->pluginApi = $pluginApi;
    }

    /**
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     *
     */
    public function __call($method, $parameters)
    {
        if (!$this->pluginApi) {
            abort(404);
        }

        if (!method_exists($this->pluginApi, $method)) {
            throw new BadMethodCallException(sprintf(
                'Method %s::%s does not exist.',
                static::class,
                $method
            ));
        }

        // create html
        $result = call_user_func_array([$this->pluginApi, $method], array_values($parameters));

        return $result;
    }
}
