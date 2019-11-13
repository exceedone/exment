<?php

namespace Exceedone\Exment\Form\Tools;

use Encore\Admin\Facades\Admin;

/**
 * SWAL button
 */
class SwalInputButton
{
    protected $title;
    protected $text;
    protected $html;
    protected $url;
    protected $label;
    protected $redirectUrl;
    protected $confirmKeyword;
    protected $icon;
    protected $confirmError;
    protected $method = 'POST';

    public function __construct($options = [])
    {
        foreach ($options as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    protected function script($suuid)
    {
        $url = $this->url;
        $redirectUrl = $this->redirectUrl;
        $title = $this->title;
        $text = $this->text;
        $html = $this->html;
        $method = $this->method;
        $confirmKeyword = $this->confirmKeyword;

        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');

        $script = <<<SCRIPT

        $('.btn-{$suuid}').unbind('click').click(function() {
            Exment.CommonEvent.ShowSwal("$url", {
                title: "{$title}",
                text: "{$text}",
                html: "{$html}",
                input: 'text',
                method: '{$method}',
                confirm:"{$confirm}",
                cancel:"{$cancel}",
                redirect: "{$redirectUrl}",
                preConfirmValidate: function(input){
                    if (input != "{$confirmKeyword}") {
                        return "error";
                    } 
        
                    return true;
                }
            });
        });
        
SCRIPT;
        
        Admin::script($script);
    }

    public function render()
    {
        // get uuid
        $suuid = short_uuid();

        $this->script($suuid);

        return view('exment::tools.swal-input-button', [
            'suuid' => $suuid,
            'label' => $this->label ?? null,
            'button_class' => 'btn-warning',
            'icon' => $this->icon ?? 'fa-share',
            'url' => $this->url
        ]);
    }
    
    /**
     * @return string
     */
    public function __toString()
    {
        return $this->render()->render() ?? '';
    }
}
