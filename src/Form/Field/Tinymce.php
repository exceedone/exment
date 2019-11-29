<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field\Textarea;

class Tinymce extends Textarea
{
    protected $view = 'admin::form.textarea';

    protected static $js = [
        '/vendor/exment/tinymce/tinymce.min.js',
    ];

    protected $config = [];

    /**
     * Set config for tinymce.
     *
     * @param string $key
     * @param mixed  $val
     *
     * @return $this
     */
    public function config($key, $val)
    {
        $this->config[$key] = $val;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function readonly()
    {
        $this->config('readonly', '1');

        return parent::readonly();
    }

    public function render()
    {
        // Remove required attributes(for timymce bug).
        array_forget($this->attributes, 'required');
        $this->rules = array_diff($this->rules, ['required']);
        $this->rules = array_values($this->rules);

        $locale = \App::getLocale();
        
        $configs = array_merge([
            'selector' => "{$this->getElementClassSelector()}",
            'toolbar'=> ['undo redo cut copy paste | formatselect fontselect fontsizeselect ', ' bold italic underline forecolor backcolor | alignleft aligncenter alignright alignjustify outdent indent blockquote bullist numlist | hr link'],
            'plugins'=> 'textcolor hr link lists',
            'menubar' => false,
            'language' => $locale,
        ], $this->config);

        $configs = json_encode($configs);

        $this->script = <<<EOT
        tinymce.init($configs);
EOT;
        return parent::render();
    }
}
