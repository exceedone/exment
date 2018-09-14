<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;

class Link extends Field
{
    protected $view = 'exment::form.field.link';

    protected $icon = '';

    protected $button = '';

    protected $text = '';

    protected $target = '';

    protected $emptyText = '';

    /**
     * Set link target
     *
     * @return $this|mixed
     */
    public function target($target)
    {
        $this->target = $target;
        return $this;
    }

    /**
     * Set icon class name.
     *
     * @return $this|mixed
     */
    public function icon($icon)
    {
        $this->icon = $icon;
        return $this;
    }

    /**
     * Set button class name.
     *
     * @return $this|mixed
     */
    public function button($buttonClass = 'btn-default')
    {
        $this->button = $buttonClass;
        return $this;
    }

    /**
     * Set view text.
     *
     * @return $this|mixed
     */
    public function text($text)
    {
        $this->text = $text;
        return $this;
    }

    /**
     * Set text if link is empty.
     *
     * @return $this|mixed
     */
    public function emptyText($text)
    {
        $this->emptyText = $text;
        return $this;
    }

    public function render()
    {
        return parent::render()->with([
            'button' => $this->button,
            'icon'  => $this->icon,
            'text'  => $this->text,
            'emptyText'  => $this->emptyText,
            'target'  => $this->target,
        ]);
    }
}
