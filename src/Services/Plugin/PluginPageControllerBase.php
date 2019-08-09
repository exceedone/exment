<?php
namespace Exceedone\Exment\Services\Plugin;

use App\Http\Controllers\Controller;

abstract class PluginPageControllerBase extends Controller
{
    /**
     * Append css
     *
     * @var array
     */
    protected $css = [];
    
    /**
     * Append js
     *
     * @var array
     */
    protected $js = [];

    public function css($css = null){
        if (is_null($css)) {
            return $this->css;
        }
        if (is_string($css)) {
            $css = [$css];
        }

        foreach($css as $c){
            $this->css[] = $c;
        }

        return $this;
    }

    public function js($js){
        if (is_null($js)) {
            return $this->js;
        }
        if (is_string($js)) {
            $js = [$js];
        }

        foreach($js as $j){
            $this->js[] = $js;
        }

        return $this;
    }
}
