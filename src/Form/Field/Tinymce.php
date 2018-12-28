<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field\Textarea;

class Tinymce extends Textarea
{
    protected $view = 'admin::form.textarea';

    protected static $js = [
        '/vendor/exment/tinymce/tinymce.min.js',
    ];
    
    protected $rows = 10;

    public function render()
    {
        $locale = \App::getLocale();

        $this->script = <<<EOT
        tinymce.init({
            selector: ".{$this->getElementClassString()}",
            toolbar: ['undo redo cut copy paste | formatselect fontselect fontsizeselect ', ' bold italic underline forecolor backcolor | alignleft aligncenter alignright alignjustify outdent indent blockquote | hr link'],
            plugins: 'textcolor hr link',
            menubar: false,
            language: "$locale",
        });
EOT;
        return parent::render();
    }
}
