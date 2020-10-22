<?php
namespace Exceedone\Exment\Services\Calc;

use Exceedone\Exment\Services\Calc\Items;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;

/**
 * Calc service. column calc, js, etc...
 */
class CalcService
{
    /**
     * get calc display text.
     *
     * @param mixed $value
     * @return void
     */
    public static function getCalcDisplayText($value, CustomTable $custom_table)
    {
        if(is_nullorempty($value)){
            return $value;
        }

        $params = static::getCalcParamsFromString($value, $custom_table);

        foreach($params as $param){
            // replace value
            $value = str_replace(array_get($param, 'key'), array_get($param, 'displayText'), $value);
        }
        
        return $value;
    }

    
    /**
     * Create calc formula info for form.
     *
     * @param CustomTable $custom_table
     * @param array $options
     * @param array $calc_params
     * @param [type] $count_detail_array
     * @param boolean $is_default
     * @return void set above values:
     *     'formula': formula string.
     *     'target_column': Defined formula setting column.
     *     'formula_column': formula column's name. Contains trigger column.
     *     'type': string, values ['dynamic', 'summary', 'count', 'select_table'],
     *     'child_relation_name': if relation is 1:n, set child relation name.
     *     'pivot_column': if select_table, set pivot culumn's name.
     */
    public static function getCalcFormArray(CustomTable $custom_table, CustomFormBlock $custom_form_block)
    {
        $calc_formulas = [];
        $calc_counts = [];
        
        foreach ($custom_form_block->custom_form_columns as $form_column) {
            if ($form_column->form_column_type != FormColumnType::COLUMN) {
                continue;
            }
            if (!isset($form_column->custom_column)) {
                continue;
            }
            $custom_column = $form_column->custom_column;

            // get format for calc formula
            $option_calc_formula = array_get($custom_column, "options.calc_formula");
            if (is_nullorempty($option_calc_formula) || $option_calc_formula == "null") {
                continue;
            }
            if(!is_string($option_calc_formula)){
                continue;
            }

            $params = static::getCalcParamsFromString($option_calc_formula, $custom_column->custom_table_cache, $custom_form_block);
            foreach($params as $param){
                // set column name
                $formula_column = array_get($param, 'custom_column');
                // get column name as key
                $column_name = $formula_column->column_name ?? null;
                if (is_nullorempty($column_name)) {
                    continue;
                }

                $formula_key_name = $param['trigger_block'] . '-' . $column_name;
                if(!array_has($calc_formulas, $formula_key_name)){
                    $calc_formulas[$formula_key_name] = [
                        'trigger_block' => $param['trigger_block'],
                        'trigger_column' => $param['trigger_column'],
                        'formulas' => [],
                    ];
                }
                $calc_formulas[$formula_key_name]['formulas'][] = [
                    'formula_string' => $option_calc_formula,
                    'params' => $params,
                    'target_column' => $custom_column->column_name,
                ];
            }

            // if contains type "count", set 'calc_counts'
            if(collect($params)->contains(function($param){
                return in_array(array_get($param, 'type'), ['count', 'parent']);
            })){
                $child_relation_name = array_get($param, 'child_relation_name');
                if(is_nullorempty($child_relation_name)){
                    continue;
                }
                
                if(!array_has($calc_counts, $child_relation_name)){
                    $calc_counts[$child_relation_name] = [
                        'block_key' => 'default',
                        'formulas' => [],
                    ];
                }
                $calc_counts[$child_relation_name]['formulas'][] = [
                    'child_relation_name' => $child_relation_name,
                    'formula_string' => $option_calc_formula,
                    'params' => $params,
                    'target_column' => $custom_column->column_name,
                ];
            }
        }

        return ['calc_formulas' => $calc_formulas, 'calc_counts' => $calc_counts];
    }


    
    /**
     * Create calc formula info.
     *
     * @param mixed $value
     * @param CustomTable $custom_table
     * @return array above values:
     * [
     *     'custom_column': params custom column.
     *     'displayText': showing text.
     *     'type': string, values ['dynamic', 'summary', 'count', 'select_table'],
     *     'key': ex. ${XXXXX}
     *     'inner_key': ex. XXXXX
     *     'pivot_column': pivot column, if select table's column.
     *     'child_table': If type is count, child table.
     *     'target_relation_name': If type is summary, box and triggered box is defferent, so set trigger relation name.
     * ]
     */
    protected static function getCalcParamsFromString($value, CustomTable $custom_table, ?CustomFormBlock $custom_form_block = null) : array
    {
        if(is_nullorempty($value)){
            return [];
        }

        $results = [];
        // replace ${value:column_name}, ${count:table_name}, ${sum:table_name.column_name}
        $regs = [
            '\$\{count:(?<key>.+?)\}' => function($splits) use($custom_table){
                return Items\Count::getItemBySplits($splits, $custom_table);
            }, 
            '\$\{sum:(?<key>.+?)\}' => function($splits) use($custom_table){
                return Items\Sum::getItemBySplits($splits, $custom_table);
            }, 
            '\$\{value:(?<key>.+?)\}' => function($splits) use($custom_table){
                return Items\Dynamic::getItemBySplits($splits, $custom_table);
            }, 
            '\$\{select_table:(?<key>.+?)\}' => function($splits) use($custom_table){
                return Items\SelectTable::getItemBySplits($splits, $custom_table);
            }, 
            '\$\{parent:(?<key>.+?)\}' => function($splits) use($custom_table, $custom_form_block){
                return Items\ParentColumn::getItemBySplits($splits, $custom_table, $custom_form_block);
            }, 
        ];

        foreach($regs as $regKey => $regFunc){
            preg_match_all('/' . $regKey . '/i', $value, $matched);

            if(is_nullorempty($matched[0])){
                continue;
            }

            foreach($matched[0] as $index => $m){
                // split "."
                $splits = explode(".", $matched['key'][$index]);
                $result = $regFunc($splits);
                if(!$result){
                    continue;
                }
                $arr = $result->toArray();

                $arr['key'] = $m;
                $arr['inner_key'] = $matched['key'][$index];

                $results[] = $arr;
            }
        }

        return $results;
    }


    /**
     * Get column options for calc
     *
     * @param string|int|null $id
     * @param CustomTable $custom_table
     * @return array
     * [
     *     'val': set value if clicked
     *     'type': calc type
     * ]
     */
    public static function getCalcCustomColumnOptions($id, $custom_table) : \Illuminate\Support\Collection
    {
        $options = collect();

        Items\Dynamic::setCalcCustomColumnOptions($options, $id, $custom_table);
        Items\SelectTable::setCalcCustomColumnOptions($options, $id, $custom_table);
        Items\Count::setCalcCustomColumnOptions($options, $id, $custom_table);
        Items\Sum::setCalcCustomColumnOptions($options, $id, $custom_table);
        Items\ParentColumn::setCalcCustomColumnOptions($options, $id, $custom_table);

        return $options;
    }

    
    /**
     * Get Symbols
     *
     * @param string|int|null $id
     * @param CustomTable $custom_table
     * @return array
     */
    public static function getSymbols()
    {
        return collect(exmtrans('custom_column.symbols'))->map(function($symbol, $key){
            $val = null;
            switch($key){
                case 'plus':
                    $val = '+';
                    break;
                case 'minus':
                    $val = '-';
                    break;
                case 'times':
                    $val = '*';
                    break;
                case 'div':
                    $val = '/';
                    break;
            }
            return [
                'symbolkey' => $key,
                'val' => $val,
                'type' => 'symbol',
                'displayText' => exmtrans('custom_column.symbols.' . $key),
            ];
        });
    }


}
