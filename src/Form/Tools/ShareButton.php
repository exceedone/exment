<?php

namespace Exceedone\Exment\Form\Tools;

use Encore\Admin\Facades\Admin;

/**
 * Open Share button.
 */
class ShareButton
{
    protected $id;
    protected $url;

    public function __construct($id, $url)
    {
        $this->id = $id;
        $this->url = $url;
    }

    protected function script($suuid, $label)
    {
        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');

        $label = esc_html(sprintf(exmtrans('common.message.confirm_execute'), ($label ?? exmtrans('common.plugin'))));

        return <<<EOT

        $('#menu_button_$suuid').off('click').on('click', function(){
            Exment.CommonEvent.ShowSwal("$this->url", {
                title: "$label",
                confirm:"$confirm",
                cancel:"$cancel",
                data: {
                    uuid:"$suuid"
                }
            });
        });
EOT;
    }

    public function render()
    {
        // get label
        $label = exmtrans('common.shared');
        // get uuid
        $suuid = short_uuid();
        //Admin::script($this->script($suuid, $label));

        return view('exment::tools.modal-button', [
            'suuid' => $suuid,
            'label' => $label ?? null,
            'button_class' => 'btn-warning',
            'icon' => 'fa-share',
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
