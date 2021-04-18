<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;

class Html extends \Encore\Admin\Form\Field\Html
{
    /**
     * Render html field.
     *
     * @return string
     */
    public function render()
    {
        if ($this->horizontal) {
            return parent::render();
        }

        if ($this->html instanceof \Closure) {
            $this->html = $this->html->call($this->form->model(), $this->form);
        }

        if ($this->plain) {
            return $this->html;
        }

        $viewClass = $this->getViewElementClasses();

        return <<<EOT
    <div class="form-group-showhtml">
        <label  class="{$viewClass['label']} control-label">{$this->label}</label>
        <div class="{$viewClass['field']}">
            {$this->html}
        </div>
    </div>
EOT;
    }
}
