<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Middleware\Initialize;
use Encore\Admin\Widgets\Form;
use Tests\TestCase;

/**
 * Validation tests for laravel-admin form fields as Exment registers them.
 *
 * Each test builds a widget form with a single field named "foo", validates one value
 * against it, and checks whether the value passes or which error message is returned.
 * Field types added by Exment (switchbool, checkboxone) are registered through
 * Initialize::registeredLaravelAdmin(), so they are covered here too.
 *
 * Option-based fields (select, multipleSelect, checkbox, checkboxone, radio, switch,
 * switchbool) are validated by HasOptionRule: null and empty values always pass, and
 * every given value must be one of the option keys.
 */
class LaravelAdminFieldTest extends TestCase
{
    use TestTrait;


    // number ----------------------------------------------------
    // number() has the "nullable|numeric" rules. min(), max() and between() add range rules
    // whose boundary values are inclusive.

    /**
     * number: an integer passes.
     *
     * @return void
     */
    public function testNumberSuccess1()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo');
        }, 1, true);
    }

    /**
     * number: a numeric string ("1") passes.
     *
     * @return void
     */
    public function testNumberSuccess2()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo');
        }, "1", true);
    }

    /**
     * number: null passes because the field is nullable.
     *
     * @return void
     */
    public function testNumberSuccess3()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo');
        }, null, true);
    }

    /**
     * number: a non-numeric string fails with the "numeric" error.
     *
     * @return void
     */
    public function testNumberError1()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo');
        }, "aaa", false, [
            'foo' => [$this->getErrorMessage('numeric', 'Foo')],
        ]);
    }

    /**
     * number: an array fails with the "numeric" error, even if every element is a number.
     *
     * @return void
     */
    public function testNumberError2()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo');
        }, [1, 2], false, [
            'foo' => [$this->getErrorMessage('numeric', 'Foo')],
        ]);
    }


    /**
     * number min(0): the boundary value 0 passes.
     *
     * @return void
     */
    public function testNumberMinSuccess1()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo')
            ->min(0);
        }, 0, true);
    }

    /**
     * number min(0): a value above the minimum passes.
     *
     * @return void
     */
    public function testNumberMinSuccess2()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo')
            ->min(0);
        }, 1, true);
    }

    /**
     * number min(0): a value below the minimum fails with the "min.numeric" error.
     *
     * @return void
     */
    public function testNumberMinError()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo')
                ->min(0);
        }, -1, false, [
            'foo' => [$this->getErrorMessage('min.numeric', 'Foo', ['min' => 0])],
        ]);
    }


    /**
     * number max(100): the boundary value 100 passes.
     *
     * @return void
     */
    public function testNumberMaxSuccess1()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo')
            ->max(100);
        }, 100, true);
    }

    /**
     * number max(100): a value below the maximum passes.
     *
     * @return void
     */
    public function testNumberMaxSuccess2()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo')
                ->max(100);
        }, 1, true);
    }

    /**
     * number max(100): a value above the maximum fails with the "max.numeric" error.
     *
     * @return void
     */
    public function testNumberMaxError()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo')
                ->max(100);
        }, 200, false, [
            'foo' => [$this->getErrorMessage('max.numeric', 'Foo', ['max' => 100])],
        ]);
    }


    /**
     * number between(0, 100): the lower boundary 0 passes.
     *
     * @return void
     */
    public function testNumberBetweenSuccess1()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo')
            ->between(0, 100);
        }, 0, true);
    }

    /**
     * number between(0, 100): a value just above the lower boundary passes.
     *
     * @return void
     */
    public function testNumberBetweenSuccess2()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo')
            ->between(0, 100);
        }, 1, true);
    }

    /**
     * number between(0, 100): a value just below the upper boundary passes.
     *
     * @return void
     */
    public function testNumberBetweenSuccess3()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo')
            ->between(0, 100);
        }, 99, true);
    }

    /**
     * number between(0, 100): the upper boundary 100 passes.
     *
     * @return void
     */
    public function testNumberBetweenSuccess4()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo')
            ->between(0, 100);
        }, 100, true);
    }

    /**
     * number between(0, 100): a value below the range fails with the "between.numeric" error.
     *
     * @return void
     */
    public function testNumberBetweenError1()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo')
            ->between(0, 100);
        }, -1, false, [
            'foo' => [$this->getErrorMessage('between.numeric', 'Foo', ['min' => 0, 'max' => 100])],
        ]);
    }

    /**
     * number between(0, 100): a value above the range fails with the "between.numeric" error.
     *
     * @return void
     */
    public function testNumberBetweenError2()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo')
            ->between(0, 100);
        }, 101, false, [
            'foo' => [$this->getErrorMessage('between.numeric', 'Foo', ['min' => 0, 'max' => 100])],
        ]);
    }




    // select ----------------------------------------------------
    // The value must be one of the option keys of getSelectOption() ("x", "y", "z").
    // Option labels ("valueX" etc.) are not accepted.

    /**
     * select: an option key ("x") passes.
     *
     * @return void
     */
    public function testSelectSuccess1()
    {
        $this->_testLaravelField(function ($form) {
            $form->select('foo')
                ->options($this->getSelectOption());
        }, 'x', true);
    }

    /**
     * select: another option key ("y") passes.
     *
     * @return void
     */
    public function testSelectSuccess2()
    {
        $this->_testLaravelField(function ($form) {
            $form->select('foo')
                ->options($this->getSelectOption());
        }, 'y', true);
    }

    /**
     * select: null (nothing selected) passes.
     *
     * @return void
     */
    public function testSelectSuccess3()
    {
        $this->_testLaravelField(function ($form) {
            $form->select('foo')
                ->options($this->getSelectOption());
        }, null, true);
    }

    /**
     * select: a value that is not an option key fails with the "not_in_option" error.
     *
     * @return void
     */
    public function testSelectError1()
    {
        $this->_testLaravelField(function ($form) {
            $form->select('foo')
                ->options($this->getSelectOption());
        }, 'a', false, [
            'foo' => [trans("admin.validation.not_in_option")],
        ]);
    }


    /**
     * select: a string made of option keys ("xyz") is not split and fails with the
     * "not_in_option" error.
     *
     * @return void
     */
    public function testSelectError2()
    {
        $this->_testLaravelField(function ($form) {
            $form->select('foo')
                ->options($this->getSelectOption());
        }, 'xyz', false, [
            'foo' => [trans("admin.validation.not_in_option")],
        ]);
    }




    // multipleSelect ----------------------------------------------------
    // Accepts a single key or an array of keys. Every element must be an option key.

    /**
     * multipleSelect: a single key given as a string passes.
     *
     * @return void
     */
    public function testMultipleSelectSuccess1()
    {
        $this->_testLaravelField(function ($form) {
            $form->multipleSelect('foo')
                ->options($this->getSelectOption());
        }, 'x', true);
    }

    /**
     * multipleSelect: an array with one key passes.
     *
     * @return void
     */
    public function testMultipleSelectSuccess2()
    {
        $this->_testLaravelField(function ($form) {
            $form->multipleSelect('foo')
                ->options($this->getSelectOption());
        }, ['y'], true);
    }

    /**
     * multipleSelect: an array with several keys passes.
     *
     * @return void
     */
    public function testMultipleSelectSuccess3()
    {
        $this->_testLaravelField(function ($form) {
            $form->multipleSelect('foo')
                ->options($this->getSelectOption());
        }, ['x', 'y'], true);
    }

    /**
     * multipleSelect: null (nothing selected) passes.
     *
     * @return void
     */
    public function testMultipleSelectSuccess4()
    {
        $this->_testLaravelField(function ($form) {
            $form->multipleSelect('foo')
                ->options($this->getSelectOption());
        }, null, true);
    }

    /**
     * multipleSelect: an empty array (nothing selected) passes.
     *
     * @return void
     */
    public function testMultipleSelectSuccess5()
    {
        $this->_testLaravelField(function ($form) {
            $form->multipleSelect('foo')
                ->options($this->getSelectOption());
        }, [], true);
    }

    /**
     * multipleSelect: a string that is not an option key fails with the "not_in_option" error.
     *
     * @return void
     */
    public function testMultipleSelectError1()
    {
        $this->_testLaravelField(function ($form) {
            $form->multipleSelect('foo')
                ->options($this->getSelectOption());
        }, 'a', false, [
            'foo' => [trans("admin.validation.not_in_option")],
        ]);
    }

    /**
     * multipleSelect: an array containing a value that is not an option key fails with the
     * "not_in_option" error.
     *
     * @return void
     */
    public function testMultipleSelectError2()
    {
        $this->_testLaravelField(function ($form) {
            $form->multipleSelect('foo')
                ->options($this->getSelectOption());
        }, ['xyz'], false, [
            'foo' => [trans("admin.validation.not_in_option")],
        ]);
    }


    // switch ----------------------------------------------------
    // The options are the on/off state values: 1 (ON) and 0 (OFF).

    /**
     * switch: the OFF value 0 passes.
     *
     * @return void
     */
    public function testSwitchSuccess1()
    {
        $this->_testLaravelField(function ($form) {
            $form->switch('foo');
        }, 0, true);
    }

    /**
     * switch: the ON value given as a string ("1") passes.
     *
     * @return void
     */
    public function testSwitchSuccess2()
    {
        $this->_testLaravelField(function ($form) {
            $form->switch('foo');
        }, "1", true);
    }

    /**
     * switch: null passes.
     *
     * @return void
     */
    public function testSwitchSuccess4()
    {
        $this->_testLaravelField(function ($form) {
            $form->switch('foo');
        }, null, true);
    }

    /**
     * switch: a value other than the state values fails with the "not_in_option" error.
     *
     * @return void
     */
    public function testSwitchError1()
    {
        $this->_testLaravelField(function ($form) {
            $form->switch('foo');
        }, 'a', false, [
            'foo' => [trans("admin.validation.not_in_option")],
        ]);
    }

    /**
     * switch: an array containing a value other than the state values fails with the
     * "not_in_option" error.
     *
     * @return void
     */
    public function testSwitchError2()
    {
        $this->_testLaravelField(function ($form) {
            $form->switch('foo');
        }, ['bbbb'], false, [
            'foo' => [trans("admin.validation.not_in_option")],
        ]);
    }


    // switchbool ----------------------------------------------------
    // Exment's switch field with "1" (YES) and "0" (NO) as its state values.
    // Boolean values are also accepted.

    /**
     * switchbool: the NO value given as an integer (0) passes.
     *
     * @return void
     */
    public function testSwitchBoolSuccess1()
    {
        $this->_testLaravelField(function ($form) {
            $form->switchbool('foo');
        }, 0, true);
    }

    /**
     * switchbool: the YES value "1" passes.
     *
     * @return void
     */
    public function testSwitchBoolSuccess2()
    {
        $this->_testLaravelField(function ($form) {
            $form->switchbool('foo');
        }, "1", true);
    }

    /**
     * switchbool: false passes (treated as an empty value).
     *
     * @return void
     */
    public function testSwitchBoolSuccess3()
    {
        $this->_testLaravelField(function ($form) {
            $form->switchbool('foo');
        }, false, true);
    }

    /**
     * switchbool: true passes (matches the YES value "1").
     *
     * @return void
     */
    public function testSwitchBoolSuccess4()
    {
        $this->_testLaravelField(function ($form) {
            $form->switchbool('foo');
        }, true, true);
    }

    /**
     * switchbool: null passes.
     *
     * @return void
     */
    public function testSwitchBoolSuccess5()
    {
        $this->_testLaravelField(function ($form) {
            $form->switchbool('foo');
        }, null, true);
    }

    /**
     * switchbool: a value other than the state values fails with the "not_in_option" error.
     *
     * @return void
     */
    public function testSwitchBoolError1()
    {
        $this->_testLaravelField(function ($form) {
            $form->switchbool('foo');
        }, 'a', false, [
            'foo' => [trans("admin.validation.not_in_option")],
        ]);
    }

    /**
     * switchbool: an array containing a value other than the state values fails with the
     * "not_in_option" error.
     *
     * @return void
     */
    public function testSwitchBoolError2()
    {
        $this->_testLaravelField(function ($form) {
            $form->switchbool('foo');
        }, ['bbbb'], false, [
            'foo' => [trans("admin.validation.not_in_option")],
        ]);
    }


    // checkbox ----------------------------------------------------
    // Validated the same way as multipleSelect: a single key or an array of keys.

    /**
     * checkbox: a single key given as a string passes.
     *
     * @return void
     */
    public function testCheckboxSuccess1()
    {
        $this->_testLaravelField(function ($form) {
            $form->checkbox('foo')
                ->options($this->getSelectOption());
        }, 'x', true);
    }

    /**
     * checkbox: an array with one key passes.
     *
     * @return void
     */
    public function testCheckboxSuccess2()
    {
        $this->_testLaravelField(function ($form) {
            $form->checkbox('foo')
                ->options($this->getSelectOption());
        }, ['y'], true);
    }

    /**
     * checkbox: an array with several keys passes.
     *
     * @return void
     */
    public function testCheckboxSuccess3()
    {
        $this->_testLaravelField(function ($form) {
            $form->checkbox('foo')
                ->options($this->getSelectOption());
        }, ['x', 'y'], true);
    }

    /**
     * checkbox: null (nothing checked) passes.
     *
     * @return void
     */
    public function testCheckboxSuccess4()
    {
        $this->_testLaravelField(function ($form) {
            $form->checkbox('foo')
                ->options($this->getSelectOption());
        }, null, true);
    }

    /**
     * checkbox: an empty array (nothing checked) passes.
     *
     * @return void
     */
    public function testCheckboxSuccess5()
    {
        $this->_testLaravelField(function ($form) {
            $form->checkbox('foo')
                ->options($this->getSelectOption());
        }, [], true);
    }

    /**
     * checkbox: a string that is not an option key fails with the "not_in_option" error.
     *
     * @return void
     */
    public function testCheckboxError1()
    {
        $this->_testLaravelField(function ($form) {
            $form->checkbox('foo')
                ->options($this->getSelectOption());
        }, 'a', false, [
            'foo' => [trans("admin.validation.not_in_option")],
        ]);
    }

    /**
     * checkbox: an array containing a value that is not an option key fails with the
     * "not_in_option" error.
     *
     * @return void
     */
    public function testCheckboxError2()
    {
        $this->_testLaravelField(function ($form) {
            $form->checkbox('foo')
                ->options($this->getSelectOption());
        }, ['xyz'], false, [
            'foo' => [trans("admin.validation.not_in_option")],
        ]);
    }



    // checkboxone ----------------------------------------------------
    // Exment's single checkbox field. The value must be one of the option keys.

    /**
     * checkboxone: an option key passes.
     *
     * @return void
     */
    public function testCheckboxOneSuccess1()
    {
        $this->_testLaravelField(function ($form) {
            $form->checkboxone('foo')
                ->options($this->getSelectOption());
        }, 'x', true);
    }

    /**
     * checkboxone: null (unchecked) passes.
     *
     * @return void
     */
    public function testCheckboxOneSuccess2()
    {
        $this->_testLaravelField(function ($form) {
            $form->checkboxone('foo')
                ->options($this->getSelectOption());
        }, null, true);
    }

    /**
     * checkboxone: a value that is not an option key fails with the "not_in_option" error.
     *
     * @return void
     */
    public function testCheckboxOneError1()
    {
        $this->_testLaravelField(function ($form) {
            $form->checkboxone('foo')
                ->options($this->getSelectOption());
        }, 'a', false, [
            'foo' => [trans("admin.validation.not_in_option")],
        ]);
    }

    /**
     * checkboxone: an array containing a value that is not an option key fails with the
     * "not_in_option" error.
     *
     * @return void
     */
    public function testCheckboxOneError2()
    {
        $this->_testLaravelField(function ($form) {
            $form->checkboxone('foo')
                ->options($this->getSelectOption());
        }, ['xyz'], false, [
            'foo' => [trans("admin.validation.not_in_option")],
        ]);
    }




    // radio ----------------------------------------------------
    // The value must be one of the option keys.

    /**
     * radio: an option key passes.
     *
     * @return void
     */
    public function testRadioSuccess1()
    {
        $this->_testLaravelField(function ($form) {
            $form->radio('foo')
                ->options($this->getSelectOption());
        }, 'x', true);
    }

    /**
     * radio: null (nothing selected) passes.
     *
     * @return void
     */
    public function testRadioSuccess2()
    {
        $this->_testLaravelField(function ($form) {
            $form->radio('foo')
                ->options($this->getSelectOption());
        }, null, true);
    }

    /**
     * radio: a value that is not an option key fails with the "not_in_option" error.
     *
     * @return void
     */
    public function testRadioError1()
    {
        $this->_testLaravelField(function ($form) {
            $form->radio('foo')
                ->options($this->getSelectOption());
        }, 'a', false, [
            'foo' => [trans("admin.validation.not_in_option")],
        ]);
    }

    /**
     * radio: an array containing a value that is not an option key fails with the
     * "not_in_option" error.
     *
     * @return void
     */
    public function testRadioError2()
    {
        $this->_testLaravelField(function ($form) {
            $form->radio('foo')
                ->options($this->getSelectOption());
        }, ['xyz'], false, [
            'foo' => [trans("admin.validation.not_in_option")],
        ]);
    }

    /**
     * Execute laravel admin's test.
     *
     * Boots laravel-admin with Exment's custom fields, adds the field defined in the callback
     * to a new widget form, and validates ['foo' => $value].
     * When $testResult is true, validation must pass. When it is false, validation must fail
     * and the error messages must match $errors.
     *
     * @param \Closure $fieldOptionCallback adds the "foo" field to the form. Receives the form.
     * @param mixed $value validation value.
     * @param bool $testResult expects test result.
     * @param array<mixed> $errors expected error messages, keyed by field name. Only checked when $testResult is false.
     * @return void
     */
    protected function _testLaravelField(\Closure $fieldOptionCallback, $value, bool $testResult, array $errors = [])
    {
        \Admin::bootstrap();
        Initialize::registeredLaravelAdmin();

        $this->initAllTest();

        $form = new Form();

        $fieldOptionCallback($form);

        $messages = $form->validationMessages([
            'foo' => $value,
        ]);

        if ($testResult === true) {
            $this->assertTrue($messages === false, 'This test expects true, but result is false. message is ' . json_encode($messages));
        } else {
            $this->assertTrue($messages !== false, 'This test expects false, but result is true');
            // @phpstan-ignore-next-line
            $this->assertJsonExment($errors, $messages->getMessages());
        }
    }

    /**
     * Get the Laravel validation message for the given rule, with ":attribute" replaced by $column.
     *
     * @param string $validatekey rule key in the validation language file (e.g. "numeric", "min.numeric")
     * @param string $column attribute name shown in the message
     * @param array<string, int> $messages other placeholder values (e.g. ['min' => 0])
     * @return mixed
     */
    protected function getErrorMessage($validatekey, $column, array $messages = [])
    {
        $array = array_merge($messages, ['attribute' => $column]);
        return trans("validation.$validatekey", $array);
    }

    /**
     * Get select option
     *
     * Option keys are "x", "y" and "z". Validation checks the keys, not the labels.
     *
     * @return array<string, string>
     */
    protected function getSelectOption()
    {
        return [
            'x' => 'valueX',
            'y' => 'valueY',
            'z' => 'valueZ',
        ];
    }
}
