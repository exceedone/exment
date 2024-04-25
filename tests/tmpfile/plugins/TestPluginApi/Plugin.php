<?php

namespace App\Plugins\TestPluginApi;

use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Plugin\PluginApiBase;
use Validator;

class Plugin extends PluginApiBase
{
    /**
     * カラム名からカスタム列情報を取得するサンプルです
     * @return mixed
     */
    public function column()
    {
        // リクエストパラメータをチェックします（tableとcolumnはそれぞれ必須）
        $validator = Validator::make(request()->all(), [
            'table' => 'required',
            'column' => 'required',
        ]);
        if ($validator->fails()) {
            return abortJson(400, [
                'errors' => $this->getErrorMessages($validator)
            ], ErrorCode::VALIDATION_ERROR());
        }

        // リクエストパラメータからテーブル名とカラム名を取得します
        $table_name = request()->get('table');
        $column_name = request()->get('column');

        // カスタムテーブル情報を取得します
        /** @var CustomTable|null $custom_table */
        $custom_table = CustomTable::where('table_name', $table_name)->first();
        if (!isset($custom_table)) {
            return abort(400);
        }

        // 権限があるかチェックします
        if (!$custom_table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return abortJson(403, trans('admin.deny'));
        }

        // カスタム列情報を取得します
        $column = $custom_table->custom_columns()->where('column_name', $column_name)->first();
        if (!isset($column)) {
            return abort(400);
        }

        return $column;
    }

    /**
     * カラム名からカスタム列情報を取得するサンプルです
     * ※URLでテーブル名とカラム名を指定しています
     * @return mixed
     */
    public function tablecolumn($table, $column)
    {
        // カスタムテーブル情報を取得します
        /** @var CustomTable|null $custom_table */
        $custom_table = CustomTable::where('table_name', $table)->first();
        if (!isset($custom_table)) {
            return abort(400);
        }

        // 権限があるかチェックします
        if (!$custom_table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return abortJson(403, trans('admin.deny'));
        }

        // カスタム列情報を取得します
        $custom_column = $custom_table->custom_columns()->where('column_name', $column)->first();
        if (!isset($custom_column)) {
            return abort(400);
        }

        return $custom_column;
    }
}
