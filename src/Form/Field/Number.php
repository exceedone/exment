<?php

namespace Exceedone\Exment\Form\Field;

class Number extends \Encore\Admin\Form\Field\Number
{
    //protected $rules = [];

    // @phpstan-ignore-next-line
    protected $disableUpdown = false;
    // @phpstan-ignore-next-line
    protected $defaultEmpty = false;
    // @phpstan-ignore-next-line
    protected $fieldWidth = 100;

    // @phpstan-ignore-next-line
    public function disableUpdown()
    {
        $this->disableUpdown = true;
        return $this;
    }

    // @phpstan-ignore-next-line
    public function defaultEmpty()
    {
        $this->defaultEmpty = true;
        return $this;
    }

    // @phpstan-ignore-next-line
    public function setFieldWidth($fieldWidth)
    {
        $this->fieldWidth = $fieldWidth;
        return $this;
    }

    public function render()
    {
        if (!$this->defaultEmpty) {
            $this->default((int) $this->default);
        }

        if (array_has($this->attributes, 'readonly')) {
            $this->disableUpdown = true;
        }

        // if not $disableUpdown
        if (!$this->disableUpdown) {
            // get class remoiving dot
            $classname = str_replace('.', '', $this->getElementClassSelector(false));
            $this->script = <<<EOT
$('{$this->getElementClassSelector()}:not(.initialized)')
    .addClass('initialized')
    .bootstrapNumber({
        upClass: 'success btn-number-{$classname}',
        downClass: 'primary btn-number-{$classname}',
        center: true
    });

EOT;
            $this->setElementClass('disableNumberFormat');
            $this->prepend('')->defaultAttribute('style', 'width: '.$this->fieldWidth.'px');
        } else {
            $this->defaultAttribute('style', 'max-width: 200px;');
        }

        $grandParent = get_parent_class(get_parent_class($this));
        return $grandParent::render();
    }
}
