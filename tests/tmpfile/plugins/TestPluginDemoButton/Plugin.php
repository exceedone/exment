<?php
namespace App\Plugins\TestPluginDemoButton;

use Exceedone\Exment\Services\Plugin\PluginButtonBase;

class Plugin extends PluginButtonBase
{
    /**
     * Plugin Button
     */
    public function execute()
    {
        \Log::debug('Plugin calling');

        // true : 「○○が正常に完了しました！この後は××の処理を行ってください」と、独自メッセージを表示する
        if ($this->custom_value->getValue('multiples_of_3')  == '1') {
            return [
                'result' => true,
                'swaltext' => '正常です。',
            ];
        } else {
            return [
                'result' => false,
                'swaltext' => 'エラーです。',
            ];
        }
    }

    /**
    * (v3.4.3対応)画面にボタンを表示するかどうかの判定。デフォルトはtrue
    * 
    * @return bool true: 描写する false 描写しない
    */
    public function enableRender(){
        // 例1：選択しているデータのidが2の場合ボタンを表示する
        return $this->custom_value->id % 2 === 0;
    }
}