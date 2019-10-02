<?php
namespace Exceedone\Exment\Services\Plugin;

/**
 * Instantiated when no special processing is prepared in the plugin(Document) / プラグイン(ドキュメント)で、特別な処理を用意しない場合にインスタンス化されるクラス
 */
class PluginDocumentDefault extends PluginDocumentBase
{
    
    /**
     * execute before creating document
     */
    protected function executing()
    {
    }
    
    /**
     * execute after creating document
     */
    protected function executed()
    {
    }
}
