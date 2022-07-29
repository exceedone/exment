<?php

namespace App\Plugins\TestPluginImport;

use Exceedone\Exment\Services\Plugin\PluginImportBase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Exceedone\Exment\Enums\SystemTableName;

class Plugin extends PluginImportBase
{
    /**
     * execute
     */
    public function execute()
    {
        $path = $this->file->getRealPath();

        $reader = $this->createReader();
        $spreadsheet = $reader->load($path);

        // Sheet1のB4セルの内容でuserマスタを読み込みます
        $sheet = $spreadsheet->getSheetByName('Sheet1');
        $user_name = getCellValue('F3', $sheet, true);
        $user = getModelName(SystemTableName::USER)::where('value->user_name', $user_name)->first();

        // Sheet1のヘッダ部分に記載された情報で親データを編集します
        $parent = [
            'value->text' => getCellValue('B3', $sheet, true),
            'value->integer' => getCellValue('D3', $sheet, true),
            'value->user' => $user->id,
            'value->index_text' => getCellValue('B4', $sheet, true),
            'value->date' => getCellValue('D4', $sheet, true),
            'value->odd_even' => getCellValue('F4', $sheet, true),
            'value->init_text' => 'plugin_unit_test',
        ];
        // 親テーブルにレコードを追加します
        $record = getModelName('parent_table')::create($parent);

        // Sheet1の7行目～15行目に記載された明細情報を元に子データを出力します
        for ($i = 7; $i <= 15; $i++) {
            $select_table_text = getCellValue("C$i", $sheet, true);
            if (!isset($select_table_text)) {
                break;
            }
            $select_table = getModelName('custom_value_view_all')::where('value->index_text', $select_table_text)->first();
            $child = [
                'parent_id' => $record->id,
                'parent_type' => 'parent_table',
                'value->text' => getCellValue("A$i", $sheet, true),
                'value->currency' => getCellValue("B$i", $sheet, true),
                'value->select_table' => isset($select_table) ? $select_table->id : null,
                'value->index_text' => getCellValue("D$i", $sheet, true),
                'value->date' => getCellValue("E$i", $sheet, true),
                'value->odd_even' => getCellValue("F$i", $sheet, true),
            ];
            // 子テーブルにレコードを追加します
            getModelName('child_table')::create($child);
        }

        return true;
    }

    protected function createReader()
    {
        return IOFactory::createReader('Xlsx');
    }
}
