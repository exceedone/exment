<?php

/**
 * Execute Batch
 */
namespace Exceedone\Exment\Services\Plugin;

class PluginPageBase
{
    use PluginBase;
    
    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

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

    public function _plugin(){
        return $this->plugin;
    }

    public function _css($css = null){
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

    public function _js($js = null){
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
