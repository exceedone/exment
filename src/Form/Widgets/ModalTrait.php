<?php

namespace Exceedone\Exment\Form\Widgets;

trait ModalTrait
{
    /**
     * @var array
     */
    protected $modalAttributes = [];
    /**
     * @var array
     */
    protected $modalInnerAttributes = [];

    /**
     * Modal header name
     *
     * @var string
     */
    protected $modalHeader;

    /**
     * Add modal header.
     *
     * @param string       $header
     *
     * @return $this
     */
    public function modalHeader($header)
    {
        $this->modalHeader = $header;
        return $this;
    }
    /**
     * Add modal attributes.
     *
     * @param string|array $attr
     * @param string       $value
     *
     * @return $this
     */
    public function modalAttribute($attr, $value = '')
    {
        return $this->modal_attribute('modalAttributes', $attr, $value);
    }
    /**
     * Add modal attributes.
     *
     * @param string|array $attr
     * @param string       $value
     *
     * @return $this
     */
    public function modalInnerAttribute($attr, $value = '')
    {
        return $this->modal_attribute('modalInnerAttributes', $attr, $value);
    }
    /**
     * Add modal attributes. fro protected
     *
     * @param string|array $attr
     * @param string       $value
     *
     * @return $this
     */
    protected function modal_attribute($arrayName, $attr, $value = '')
    {
        if (is_array($attr)) {
            foreach ($attr as $key => $value) {
                $this->modal_attribute($arrayName, $key, $value);
            }
        } else {
            $this->$arrayName[$attr] = $value;
        }
        return $this;
    }

    /**
     * @param $attributes
     * @return string
     */
    protected function convert_attribute($attributes)
    {
        $html = [];
        foreach ($attributes as $key => $val) {
            $html[] = "$key=\"$val\"";
        }

        return implode(' ', $html) ?: '';
    }

    protected function setModalAttributes()
    {
        $this->modalAttributes = array_merge([
            'tabindex' => -1,
            'role' => 'dialog',
            'aria-labelledby' => 'myModalLabel',
            'data-backdrop' => 'static',
            'id' => 'modal-form',
            'class' => 'modal fade',
        ], $this->modalAttributes);

        $this->modalInnerAttributes = array_merge([
            'role' => 'document',
            'class' => 'exment-modal-dialog modal-dialog modal-lg',
        ], $this->modalInnerAttributes);
    }
}
