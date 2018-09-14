<?php

namespace Exceedone\Exment\Form\Widgets;

use Illuminate\Contracts\Support\Renderable;
use Encore\Admin\Widgets\Widget;

class Modal extends Widget implements Renderable
{
    /**
     * @var string
     */
    protected $view = 'exment::widgets.modal';

    /**
     * @var string
     */
    protected $label = '';

    /**
     * @var string
     */
    protected $body = '';

    /**
     * Modal constructor.
     *
     * @param array $headers
     * @param array $rows
     * @param array $style
     */
    public function __construct($label = '', $body = '')
    {
        $this->setLabel($label);
        $this->setBody($body);

        $this->class("modal fade");
    }

    /**
     * Set modal label
     *
     * @param string $label
     *
     * @return $this
     */
    public function setLabel($label)
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Set modal label
     *
     * @param string $label
     *
     * @return $this
     */
    public function setBody($body)
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Render the table.
     *
     * @return string
     */
    public function render()
    {
        $vars = [
            'label'       => $this->label,
            'body'       => $this->body,
            'attributes' => $this->formatAttributes(),
        ];

        return view($this->view, $vars)->render();
    }
}
