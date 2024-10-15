<?php

namespace Exceedone\Exment\Tests\Unit;

use Illuminate\Validation\ValidationException;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Services\FormHelper;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Form\Field\Embeds;

class CustomColumnFormValidationTest extends UnitTestBase
{
    // FILE(Multiple) ----------------------------------------------------

    public function testFileMultiple()
    {
        $errors = $this->validationMessages('file_multiple', [ 
                'file_multiple' => ['test.txt', 'sitelogo.png'],
            ]
        );

        $this->assertFalse($errors);
    }

    public function testFileMultipleNG()
    {
        $errors = $this->validationMessages('file_multiple', [ 
                'file_multiple' => ['test.txt', 'site?logo.png'],
            ]
        );

        $this->assertJsonExment($errors->get('value.file_multiple'), [
            exmtrans('validation.filename_not_allow', ['attribute' => 'file multiple'])
        ], false);
    }

    protected function validationMessages(string $column_name, array $values)
    {
        $this->login();
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $custom_form = CustomForm::getDefault($custom_table);
        $form_item = $custom_form->form_item;
        $form = $form_item->form();
        $embeds = new Embeds('value', [exmtrans("common.input"), function ($form) use ($custom_table, $column_name) {
                $custom_column = CustomColumn::getEloquent($column_name, $custom_table);
                $field = FormHelper::getFormField($custom_table, $custom_column);
                $form->pushField($field);
            }]);
        $form->pushField($embeds);

        $errors = $form->validationMessages([
            'value' => $values
        ]);

        return $errors;
    }

    // ...
    protected function login($id = null)
    {
        $this->be(LoginUser::find($id ?? 1));
    }
}
