<?php

namespace App\Plugins\TestPluginGrid;

use Exceedone\Exment\Services\Plugin\PluginGridBase;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomColumn;

class Plugin extends PluginGridBase
{
    /**
     *
     */
    public function grid()
    {
        $values = $this->values();
        return view('exment_test_plugin_grid::sample', ['values' => $values]);
    }


    protected function values(){
        $query = $this->custom_table->getValueQuery();

        // データのフィルタをソート情報をセット
        $this->custom_view->filterSortModel($query);

        // 値を取得
        $items = collect();
        $query->chunk(1000, function($values) use(&$items){
            $items = $items->merge($values);
        });

        $boards = $this->getBoardItems($items);

        return $boards;
    }


    protected function getBoardItems($items){
        $category = CustomColumn::getEloquent($this->custom_view->getCustomOption('category'));
        $options = $category->createSelectOptions();

        // set boards
        $boards_dragTo = collect($options)->map(function($option, $key){
            return "board-id-$key";
        })->toArray();

        $boards = collect($options)->map(function($option, $key) use($category, $boards_dragTo){
            return [
                'id' => "board-id-$key",
                'column_name' => $category->column_name,
                'key' => $key,
                'title' => $option,
                'drapTo' => $boards_dragTo,
                'item' => [],
            ];
        })->values()->toArray();

        foreach($items as $item){
            $c = array_get($item, 'value.' . $category->column_name);
            
            foreach($boards as &$board){
                if(!isMatchString($c, $board['key'])){
                    continue;
                }

                $board['item'][] = [
                    'id' => "item-id-$item->id",
                    'title' => $item->getLabel(),
                    'dataid' => $item->id,
                    'table_name' => $this->custom_table->table_name
                ];
            }
        }

        return $boards;
    }
    

    /**
     * Set view option form for setting
     *
     * @param Form $form
     * @return void
     */
    public function setViewOptionForm($form)
    {
        //　独自設定を追加する場合
        $form->embeds('custom_options', '詳細設定', function($form){
            $form->select('category', 'カテゴリ列')
                ->options($this->custom_table->getFilteredTypeColumns([ColumnType::SELECT, ColumnType::SELECT_VALTEXT])->pluck('column_view_name', 'id'))
                ->required()
                ->help('カテゴリ列を選択してください。カンバンのボードに該当します。カスタム列種類「選択肢」「選択肢(値・見出し)」が候補に表示されます。');
        });

        //　フィルタ(絞り込み)の設定を行う場合
        static::setFilterFields($form, $this->custom_table);

        // 並べ替えの設定を行う場合
        static::setSortFields($form, $this->custom_table);
    }
}