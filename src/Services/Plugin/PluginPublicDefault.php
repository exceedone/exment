<?php

/**
 */
namespace Exceedone\Exment\Services\Plugin;

/**
 * Instantiated when no special processing is prepared in the plugin(Style, Script) / プラグイン(スタイル、スクリプト)で、特別な処理を用意しない場合にインスタンス化されるクラス
 */
class PluginPublicDefault extends PluginPublicBase
{
    use PluginBase;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }
}
