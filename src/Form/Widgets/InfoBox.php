<?php

namespace Exceedone\Exment\Form\Widgets;

use Encore\Admin\Widgets\InfoBox as AdminInfoBox;

class InfoBox extends AdminInfoBox
{
    /**
     * @var string
     */
    protected $view = 'exment::widgets.info-box';

    /**
     * InfoBox constructor.
     *
     * @param string $name
     * @param string $icon
     * @param string $color
     * @param string $link
     * @param string $info
     */
    public function __construct($name, $icon, $color, $link, $info)
    {
        parent::__construct($name, $icon, $color, $link, $info);
        $this->showLink();
        $this->target();
    }

    public function showLink($showLink = true)
    {
        $this->data['showLink'] = $showLink;

        return $this;
    }

    public function linkText($linkText)
    {
        $this->data['linkText'] = $linkText;

        return $this;
    }

    public function target($target = '_self')
    {
        $this->data['target'] = $target;

        return $this;
    }
}
