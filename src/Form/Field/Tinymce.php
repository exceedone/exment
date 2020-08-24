<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field\Textarea;
use Exceedone\Exment\Model\Define;

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

    protected function getValidElements()
    {
        $tags = Define::HTML_ALLOWED_EDITOR_DEFAULT;
        if (!is_null($c = config('exment.html_allowed_editor'))) {
            $tags = $c;
        }
        
        return $tags;
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
            'toolbar'=> ['undo redo cut copy paste | formatselect fontselect fontsizeselect ', ' bold italic underline forecolor backcolor | alignleft aligncenter alignright alignjustify outdent indent blockquote bullist numlist | hr link code'],
            'plugins'=> 'textcolor hr link lists code',
            'menubar' => false,
            'language' => $locale,
            'valid_elements' => $this->getValidElements(),
        ], $this->config);

        $configs = json_encode($configs);

        $this->script = <<<EOT
        tinymce.init($configs);
EOT;
        return parent::render();
    }
}
