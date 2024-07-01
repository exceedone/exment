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
    protected $input;
    protected $redirectUrl;
    protected $confirmKeyword;
    protected $icon;
    protected $confirmError;
    protected $btn_class;
    protected $showCancelButton = true;
    protected $type = 'warning';
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
        $input = $this->input;
        $html = $this->html;
        $type = $this->type;
        $method = $this->method;
        $confirmKeyword = $this->confirmKeyword;
        $showCancelButton = $this->showCancelButton;

        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');

        $script = <<<SCRIPT

        $('.btn-{$suuid}').unbind('click').click(function() {
            let select_ids = $('.column-__row_selector__').length > 0 ? $.admin.grid.selected() : null;
            Exment.CommonEvent.ShowSwal("$url", {
                type: "{$type}",
                title: "{$title}",
                text: "{$text}",
                html: "{$html}",
                input: "{$input}",
                method: '{$method}',
                confirm:"{$confirm}",
                cancel:"{$cancel}",
                showCancelButton: "{$showCancelButton}",
                redirect: "{$redirectUrl}",
                preConfirmValidate: function(input){
                    if('$input' != 'text'){
                        return true;
                    }
                    if (input != "{$confirmKeyword}") {
                        return "error";
                    } 
        
                    return true;
                },
                data: {
                    select_ids: select_ids
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
            'btn_class' => $this->btn_class ?? 'btn-warning',
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
