<?php

namespace Exceedone\Exment\Tests\Unit;

use ExmentAdminCore\Admin\Form;
use Illuminate\Database\Eloquent\Model;

/**
 * exment-admin-core's Form reads the relations of a saved form from the request. A request key
 * is not taken as the name of a method of the model to call: only the form's own fields are.
 * (Needs exment-admin-core 1.0.5 or later.)
 */
class AdminCoreFormInputsTest extends UnitTestBase
{
    public function testAKeyThatIsNotAFieldIsNotCalled(): void
    {
        AdminCoreFormInputsTestModel::$called = [];
        $form = new Form(new AdminCoreFormInputsTestModel());
        $form->text('name');

        $method = new \ReflectionMethod(Form::class, 'getRelationInputs');
        $method->setAccessible(true);
        $method->invoke($form, ['name' => 'x', 'wipeEverything' => '1', 'wipe_everything' => '1']);

        $this->assertSame([], AdminCoreFormInputsTestModel::$called);
    }

    public function testTheSameForTheRelationModels(): void
    {
        AdminCoreFormInputsTestModel::$called = [];
        $form = new Form(new AdminCoreFormInputsTestModel());
        $form->text('name');

        $form->getRelationModelByInputs(['name' => 'x', 'wipeEverything' => ['1']]);

        $this->assertSame([], AdminCoreFormInputsTestModel::$called);
    }
}

class AdminCoreFormInputsTestModel extends Model
{
    /** @var array<string> */
    public static $called = [];

    public function wipeEverything(): void
    {
        static::$called[] = 'wipeEverything';
    }
}
