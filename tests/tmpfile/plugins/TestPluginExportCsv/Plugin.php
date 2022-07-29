<?php

namespace App\Plugins\TestPluginExportCsv;

use Exceedone\Exment\Services\Plugin\PluginExportBase;

class Plugin extends PluginExportBase
{
    /**
     * execute
     */
    public function execute()
    {
        // ※メソッド「$this->getTmpFullPath()」で、一時tmpファイルを取得する
        // ※実行後、一時tmpファイルは自動的に削除されます。
        $tmp = $this->getTmpFullPath();

        // csvファイルを開く
        $fp = fopen($tmp, 'w');

        // すべてのシステム列・カスタム列でデータ一覧取得（配列）
        $data = $this->getData();

        // ビュー形式でデータ一覧取得（配列）
        // $data = $this->getViewData();

        // CustomValueのCollectionでデータ一覧取得
        // $data = $this->getRecords();


        foreach ($data as $fields) {
            fputcsv($fp, $fields);
        }

        fclose($fp);

        // $tmpのstring文字列を返却する
        return $tmp;
    }

    /**
     * Get download file name.
     * ファイル名を取得する
     *
     * @return string
     */
    public function getFileName(): string
    {
        return "test.csv";
    }
}
