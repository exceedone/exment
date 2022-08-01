<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field\Textarea;

class CodeEditor extends Textarea
{
    protected $view = 'admin::form.textarea';

    protected $mode = 'txt';
    protected $height;

    public function mode($mode)
    {
        $this->mode = $mode;
        return $this;
    }

    public function height(int $height)
    {
        $this->height = $height;
        return $this;
    }


    public function render()
    {
        $mode = $this->mode;
        $height = $this->height;
        $this->script = <<<EOT

        var elem = document.querySelector('{$this->getElementClassSelector()}');
        var myCodeMirror = CodeMirror.fromTextArea(elem, {
            mode: '$mode',
            lineNumbers: true,
            indentUnit: 4,
        });
EOT;
        if (!is_nullorempty($height)) {
            $this->script .= <<<EOT
        myCodeMirror.setSize(null, $height);
EOT;
        }

        return parent::render();
    }
}
