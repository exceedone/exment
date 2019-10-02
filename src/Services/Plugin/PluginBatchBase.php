<?php

namespace Exceedone\Exment\Services\Plugin;

/**
 * Plugin (batch) base class / プラグイン(バッチ)の基底クラス
 */
class PluginBatchBase
{
    use PluginBase;
    
    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Processing during batch execution. / バッチ実行時の処理
     *
     * @return void
     */
    public function execute()
    {
    }
}
