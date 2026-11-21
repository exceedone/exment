<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\DataItems\Show\PublicFormShow;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The confirm page of a public form reads the child blocks from the visitor's request by the
 * names of the relations. A key that is the name of another method of the model (save, push,
 * all, ...) is not called.
 */
class PublicFormRelationInputsTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testAKeyThatIsNotARelationOfTheFormIsNotCalled(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $custom_form = CustomForm::getDefault($table);
        $public_form = PublicForm::where('custom_form_id', $custom_form->id)->first();
        $this->assertNotNull($public_form);

        $custom_value = $table->getValueModel();
        $before = $table->getValueModel()->query()->withoutGlobalScopes()->count();

        $show_item = PublicFormShow::getItem($table, $custom_form)->custom_value($custom_value)->setPublicForm($public_form);
        $method = new \ReflectionMethod(PublicFormShow::class, 'getRelationModels');
        $method->setAccessible(true);
        $method->invoke($show_item, ['save' => '1', 'push' => '1']);

        // the record of the confirm page is not stored by the request
        $this->assertFalse($custom_value->exists);
        $this->assertSame($before, $table->getValueModel()->query()->withoutGlobalScopes()->count());
    }
}
