<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field  as AdminField;
use Exceedone\Exment\Form\EmbeddedForm;

trait FieldGroupTrait
{
    /**
     * Convert Row-Column groups
     * 
     * from:
     * [
     *     [ row: 1, column: 1, width: 1, field: $field ],
     *     [ row: 1, column: 1, width: 1, field: $field ],
     *     [ row: 1, column: 2, width: 3, field: $field ],
     *     [ row: 2, column: 1, width: 2, field: $field ],
     *     [ row: 2, column: 1, width: 2, field: $field ],
     *     [ row: 2, column: 2, width: 1, field: $field ],
     * ]
     * 
     * to:
     * [
     *     [
     *         row: 1,
     *         columns: [
     *             [
     *                 column: 1,
     *                 width: 1,
     *                 col_md: 3,
     *                 fields: [(2 fields)],
     *             ],
     *             [
     *                 column: 2,
     *                 width: 3,
     *                 col_md: 9,
     *                 fields: [(1 fields)],
     *             ],
     *         ],
     *     ],
     *     [
     *         row: 2,
     *         columns: [
     *             [
     *                 column: 1,
     *                 width: 2,
     *                 col_md: 6,
     *                 fields: [(1 fields)],
     *             ],
     *             [
     *                 column: 2,
     *                 width: 1,
     *                 col_md: 3,
     *                 fields: [(2 fields)],
     *             ],
     *         ],
     *     ]
     * ]
     *
     * @param array $fieldOptions
     * @return \Illuminate\Support\Collection
     */
    protected function convertRowColumnGroups(array $fieldOptions)
    {
        $fieldGroups = collect($fieldOptions)->sortBy(function($fieldOption, $index){
            $row = array_get($fieldOption, 'options.row', 1);
            $column = array_get($fieldOption, 'options.column', 1);
            $index = str_pad($index, 3, 0, STR_PAD_LEFT);
            return "{$row}-{$column}-{$index}";
        })
        // grid form, group row
        ->groupBy(function ($fieldOption, $key) {
            return array_get($fieldOption, 'options.row', 1);
        });

        // group column again 
        $fieldGroups = $fieldGroups->map(function($fieldGroups, $key){
            $groups = $fieldGroups->groupBy(function ($fieldOption, $key) {
                return array_get($fieldOption, 'options.column', 1);
            })->map(function($g, $key){
                return [
                    'column' => $key,
                    'width' => intval(array_get($g->first(), 'options.width', 1)),
                    'fields' => $g->map(function($g){
                        return ['field' => array_get($g, 'field')];
                    }),
                ];
            });

            return [
                'row' => $key,
                'columns' => $groups,
            ];
        });


        // Calc total width. ----------------------------------------------------
        // Ex. column:1 width:1 → total_width:1
        // Ex. column:1 width:2 and column:2 width:1 → total_width:3
        // Ex. column:1 width:3 and column:2 width:1 → total_width:4
        $totalWidth = $fieldGroups->max(function($fieldGroupRows){
            return $fieldGroupRows['columns']->sum(function ($fieldOption) {
                return $fieldOption['width'];
            });
        });
        if($totalWidth <= 0){$totalWidth = 1;}

        
        // Set col_md width using total width. ----------------------------------------------------
        $fieldGroups = $fieldGroups->map(function($fieldGroups) use($totalWidth){
            $fieldGroups['columns'] = collect($fieldGroups['columns'])->map(function ($fieldOption) use($totalWidth) {
                // if $totalWidth is 1 and vertical then col_md is 8 and offset is 2.
                $fieldOption['col_md'] = ($fieldOption['width'] * 3 * (4 / $totalWidth));

                // set field's col sm and offset
                $fieldOption['fields'] = collect($fieldOption['fields'])->map(function ($field) use($totalWidth){ 
                    if($totalWidth <= 2 && !$field['field']->getHorizontal()){
                        $field['field_sm'] = 8;
                        $field['field_offset'] = 2;
                    }
                    else{
                        $field['field_sm'] = 12;
                        $field['field_offset'] = 0;
                    }
                    return $field;
                })->toArray();
                return $fieldOption;
            })->toArray();

            return $fieldGroups;
        });

        return $fieldGroups;
    }
}
