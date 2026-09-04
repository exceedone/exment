<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;

class Link extends Field
{
    protected $view = 'exment::form.field.link';

    // @phpstan-ignore-next-line
    protected $icon = '';

    // @phpstan-ignore-next-line
    protected $button = '';

    // @phpstan-ignore-next-line
    protected $text = '';

    // @phpstan-ignore-next-line
    protected $target = '';

    // @phpstan-ignore-next-line
    protected $emptyText = '';

    /**
     * Set link target
     *
     * @return $this|mixed
     */
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
    public function emptyText($text)
    {
        $this->emptyText = $text;
        return $this;
    }

    public function render()
    {
        // @phpstan-ignore-next-line
        return parent::render()->with([
            'button' => $this->button,
            'icon'  => $this->icon,
            'text'  => $this->text,
            'emptyText'  => $this->emptyText,
            'target'  => $this->target,
        ]);
    }
}
