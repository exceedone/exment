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

        // get options
        $view_flg = CustomColumn::getEloquent($this->custom_view->getCustomOption('view_flg'));
        $hidden_flg = CustomColumn::getEloquent($this->custom_view->getCustomOption('hidden_flg'));

        if(isset($view_flg)){
            $query->where($view_flg->getQueryKey(), 1);
        }
        if(isset($hidden_flg)){
            $query->where($hidden_flg->getQueryKey(), '<>', 1);
        }

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
        $form->embeds('custom_options', '詳細設定', function($form){
            $form->select('category', 'カテゴリ列')
                ->options($this->custom_table->getFilteredTypeColumns([ColumnType::SELECT, ColumnType::SELECT_VALTEXT])->pluck('column_view_name', 'id'))
                ->required()
                ->help('カテゴリ列を選択してください。カンバンのボードに該当します。カスタム列種類「選択肢」「選択肢(値・見出し)」が候補に表示されます。');
            $form->select('view_flg', '表示フラグ列')
                ->options($this->custom_table->getFilteredTypeColumns([ColumnType::YESNO])->filter(function($custom_column){return $custom_column->index_enabled;})->pluck('column_view_name', 'id'))
                ->help('この項目を設定すると、値がYESで保存されているデータのみ、ボードに表示します。カスタム列種類「YES/NO」が候補に表示されます。');
            $form->select('hidden_flg', '非表示フラグ列')
                ->options($this->custom_table->getFilteredTypeColumns([ColumnType::YESNO])->filter(function($custom_column){return $custom_column->index_enabled;})->pluck('column_view_name', 'id'))
                ->help('この項目を設定すると、値がYESで保存されているデータは、ボードに非表示になります。カスタム列種類「YES/NO」が候補に表示されます。');
        });
    }
}