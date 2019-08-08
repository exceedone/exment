<?php

namespace Exceedone\Exment\Form\Field;

class Text extends \Encore\Admin\Form\Field\Text
{
    public function render()
    {
        $suggest_url = array_get($this->attributes, 'suggest_url');
        if (isset($suggest_url)) {
            $this->script = <<<EOT
$('{$this->getElementClassSelector()}').autocomplete({
    source: function (req, res) {
        $.ajax({
            url: "$suggest_url",
            data: {
                _token: LA.token,
                query: req.term
            },
            dataType: "json",
            type: "GET",
            success: function (data) {
                res(data);
            },
        });
    },
    autoFocus: true,
    delay: 300,
    minLength: 1
});
EOT;
        }

        return parent::render();
    }
}
