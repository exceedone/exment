<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Middleware\Initialize;
use Encore\Admin\Widgets\Form;
use Tests\TestCase;

class LaravelAdminFieldTest extends TestCase
{
    use TestTrait;


    // number ----------------------------------------------------

    /**
     * @return void
     */
    public function testNumberSuccess1()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo');
        }, 1, true);
    }

    /**
     * @return void
     */
    public function testNumberSuccess2()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo');
        }, "1", true);
    }

    /**
     * @return void
     */
    public function testNumberSuccess3()
    {
        $this->_testLaravelField(function ($form) {
            $form->number('foo');
        }, null, true);
    }

    /**
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

    /**
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

    /**
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

    /**
     * @return void
     */
    public function testSwitchSuccess1()
    {
        $this->_testLaravelField(function ($form) {
            $form->switch('foo');
        }, 0, true);
    }

    /**
     * @return void
     */
    public function testSwitchSuccess2()
    {
        $this->_testLaravelField(function ($form) {
            $form->switch('foo');
        }, "1", true);
    }

    /**
     * @return void
     */
    public function testSwitchSuccess4()
    {
        $this->_testLaravelField(function ($form) {
            $form->switch('foo');
        }, null, true);
    }

    /**
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

    /**
     * @return void
     */
    public function testSwitchBoolSuccess1()
    {
        $this->_testLaravelField(function ($form) {
            $form->switchbool('foo');
        }, 0, true);
    }

    /**
     * @return void
     */
    public function testSwitchBoolSuccess2()
    {
        $this->_testLaravelField(function ($form) {
            $form->switchbool('foo');
        }, "1", true);
    }

    /**
     * @return void
     */
    public function testSwitchBoolSuccess3()
    {
        $this->_testLaravelField(function ($form) {
            $form->switchbool('foo');
        }, false, true);
    }

    /**
     * @return void
     */
    public function testSwitchBoolSuccess4()
    {
        $this->_testLaravelField(function ($form) {
            $form->switchbool('foo');
        }, true, true);
    }

    /**
     * @return void
     */
    public function testSwitchBoolSuccess5()
    {
        $this->_testLaravelField(function ($form) {
            $form->switchbool('foo');
        }, null, true);
    }

    /**
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

    /**
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

    /**
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

    /**
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
     * @param \Closure $fieldOptionCallback
     * @param mixed $value validation value.
     * @param bool $testResult expects test result.
     * @param array<mixed> $errors setting field to form.
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
            $this->assertJsonExment($errors, $messages->getMessages());
        }
    }

    /**
     * @param string $validatekey
     * @param string $column
     * @param array<string, int> $messages
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
