<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Model\CustomTable;

trait ExmentKitPrepareTrait
{
    /**
     * Prepare custom relation for form test.
     */
    protected function createCustomRelation($parent_table, $child_table, $relation_type = 1)
    {
        $row = CustomTable::where('table_name', $child_table)->first();

        $data = [
            'child_custom_table_id' => array_get($row, 'id'),
            'relation_type' => $relation_type,
        ];
        // Create custom relation
        $this->visit(admin_url("relation/$parent_table/create"))
                ->submitForm('admin-submit', $data)
                ->seePageIs('/admin/relation/' . $parent_table)
                ->seeInElement('td', array_get($row, 'table_view_name'));
    }

    /**
     * Prepare custom table for test.
     */
    protected function createCustomTable($table_name, $search_enabled = 1, $one_record_flg = 0)
    {
        $view_name = ucwords(str_replace('_', ' ', $table_name));

        $custom_table = CustomTable::getEloquent($table_name);

        if (isset($custom_table)) {
            $this->assertTrue(true);
            return;
        }

        $redirectPath = admin_url("column/$table_name");

        $data = [
            'table_name' => $table_name,
            'table_view_name' => $view_name,
            'description' => $view_name . ' Description',
            'options[color]' => '#ff0000',
            'options[icon]' => 'fa-automobile',
            'options[search_enabled]' => $search_enabled,
            'options[one_record_flg]' => $one_record_flg,
            'options[all_user_editable_flg]' => '1'
        ];
        // Create custom table
        $this->visit(admin_url('table/create'))
                ->submitForm('admin-submit', $data)
                ->seePageIs($redirectPath);
    }

    /**
     * Prepare custom column all columntype.
     */
    protected function createCustomColumns($table_name, $targets = null)
    {
        $col_data[] = [
            'column_name' => 'integer',
            'column_view_name' => 'Integer',
            'column_type' => 'integer',
            'options' => [
                'number_min' => 10,
                'number_max' => 100,
                'index_enabled' => 1,
                'number_format' => 1,
            ],
        ];
        $col_data[] = [
            'column_name' => 'onelinetext',
            'column_view_name' => 'One Line Text',
            'column_type' => 'text',
            'options' => [
                'string_length' => 256,
                'index_enabled' => 1,
            ],
        ];
        $col_data[] = [
            'column_name' => 'dateandtime',
            'column_view_name' => 'Date and Time',
            'column_type' => 'datetime',
            'options' => [
                'index_enabled' => 1,
            ],
        ];
        $col_data[] = [
            'column_name' => 'selectfromstaticvalue',
            'column_view_name' => 'Select From Static Value',
            'column_type' => 'select',
            'options' => [
                'select_item' => 'Option 1'."\n".'Option 2',
                'multiple_enabled' => 1,
                'index_enabled' => 1,
            ],
        ];
        $col_data[] = [
            'column_name' => 'select2value',
            'column_view_name' => 'Select 2 value',
            'column_type' => 'boolean',
            'options' => [
                'true_value' => 'value1',
                'true_label' => 'label1',
                'false_value' => 'value2',
                'false_label' => 'label2',
                'multiple_enabled' => 1,
            ],
        ];
        $col_data[] = [
            'column_name' => 'yesno',
            'column_view_name' => 'Yes No',
            'column_type' => 'yesno',
        ];
        $col_data[] = [
            'column_name' => 'selectsavevalueandlabel',
            'column_view_name' => 'Select Save Value and Lable',
            'column_type' => 'select_valtext',
            'options' => [
                'select_item_valtext' => '1,Value 1'."\n".'2,Value 2',
                'multiple_enabled' => 1,
            ],
        ];
        $col_data[] = [
            'column_name' => 'multiplelinetext',
            'column_view_name' => 'Multiple Line Text',
            'column_type' => 'textarea',
            'options' => [
                'string_length' => 256,
            ],
        ];
        $col_data[] = [
            'column_name' => 'decimal',
            'column_view_name' => 'Decimal',
            'column_type' => 'decimal',
            'options' => [
                'number_min' => 10,
                'number_max' => 100,
                'index_enabled' => 1,
                'number_format' => 1,
            ],
        ];
        $col_data[] = [
            'column_name' => 'url',
            'column_view_name' => 'URL',
            'column_type' => 'url',
            'options' => [
                'index_enabled' => 1,
            ],
        ];
        $col_data[] = [
            'column_name' => 'email',
            'column_view_name' => 'Email',
            'column_type' => 'email',
            'options' => [
                'index_enabled' => 1,
            ],
        ];
        $col_data[] = [
            'column_name' => 'date',
            'column_view_name' => 'Date',
            'column_type' => 'date',
        ];
        $col_data[] = [
            'column_name' => 'time',
            'column_view_name' => 'Time',
            'column_type' => 'time',
            'options' => [
                'index_enabled' => 1,
            ],
        ];
        $col_data[] = [
            'column_name' => 'selectfromtable',
            'column_view_name' => 'Select From Table',
            'column_type' => 'select_table',
            'options' => [
                'select_target_table' => 6,
                'multiple_enabled' => 1,
            ],
        ];
        $col_data[] = [
            'column_name' => 'autonumber',
            'column_view_name' => 'Auto Number',
            'column_type' => 'auto_number',
            'options' => [
                'auto_number_type' => 'random25',
            ],
        ];
        $col_data[] = [
            'column_name' => 'image',
            'column_view_name' => 'Image',
            'column_type' => 'image',
        ];
        $col_data[] = [
            'column_name' => 'file',
            'column_view_name' => 'File',
            'column_type' => 'file',
        ];
        $col_data[] = [
            'column_name' => 'user',
            'column_view_name' => 'User',
            'column_type' => 'user',
            'options' => [
                'multiple_enabled' => 1,
            ],
        ];
        $col_data[] = [
            'column_name' => 'user_single',
            'column_view_name' => 'User Single',
            'column_type' => 'user',
            'options' => [
                'index_enabled' => 1,
            ],
        ];
        $col_data[] = [
            'column_name' => 'organization',
            'column_view_name' => 'Organization',
            'column_type' => 'organization',
            'options' => [
                'multiple_enabled' => 1,
            ],
        ];

        foreach ($col_data as $data) {
            if (is_null($targets) || in_array(array_get($data, 'column_type'), $targets) || in_array(array_get($data, 'column_name'), $targets)) {
                // Create custom column
                $this->post(admin_url("column/$table_name"), $data);
                $this->visit(admin_url("column/$table_name"))
                        ->seePageIs("/admin/column/$table_name")
                        ->seeInElement('td', array_get($data, 'column_view_name'));
            }
        }
    }
}
