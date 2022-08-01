<?php

namespace App\Plugins\TestPluginExportExcel;

// PluginExportExcelに変更
use Exceedone\Exment\Services\Plugin\PluginExportExcel;

// extendsをPluginExportExcelに変更
class Plugin extends PluginExportExcel
{
    /**
     * execute
     */
    public function execute()
    {
        // テンプレートファイルを読み込み、PhpSpreadsheetを初期化
        $spreadsheet = $this->initializeExcel('template.xlsx');
        // ※テンプレートファイルを使用せず、新規にファイルを作成する場合
        //$spreadsheet = $this->initializeExcel();

        // すべてのシステム列・カスタム列でデータ一覧取得（配列）
        // $data = $this->getData();

        // ビュー形式でデータ一覧取得（配列）
        // $data = $this->getViewData();

        // CustomValueのCollectionでデータ一覧取得
        $data = $this->getRecords();


        ///// データの独自の出力処理---ここから
        $sheet = $spreadsheet->getActiveSheet();
        $column = 3;
        foreach ($data as $record) {

            // データをループしてセット
            $sheet->setCellValue("A{$column}", $record->id); // ID
            $sheet->setCellValue("B{$column}", $record->getValue('title', true)); // タイトル
            $sheet->setCellValue("C{$column}", $record->getValue('priority', true)); // 重要度
            $sheet->setCellValue("D{$column}", $record->updated_at); // 更新日時

            $column++;
        }

        // 枠の設定
        $laseRow = $column - 1;
        $sheet->getStyle("A2:D{$laseRow}")->applyFromArray([
            'borders' => [
                'outside'=>[
                    'borderStyle'=>\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
                ],
                'inside'=>[
                    'borderStyle'=>\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_HAIR
                ],
            ],
        ]);

        // 印刷範囲の設定
        $sheet->getPageSetup()->setPrintArea("A1:D{$laseRow}");
        ///// データの独自の出力処理---ここまで


        // tmpファイルに作成したファイルを保存して、そのファイルパスを返却
        // (テンプレートとは別ファイルとして保存)
        return $this->getExcelResult($spreadsheet);
    }

    /**
     * Get download file name.
     *
     * @return string
     */
    public function getFileName(): string
    {
        return "test.xlsx";
    }
}
