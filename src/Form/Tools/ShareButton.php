<?php

namespace Exceedone\Exment\Form\Tools;

use Encore\Admin\Facades\Admin;

/**
 * Open Share button.
 */
class ShareButton
{
    protected $custom_table;
    protected $id;
    protected $is_view;
    
    public function __construct($custom_table, $id, $is_view = false)
    {
        $this->custom_table = $custom_table;
        $this->id = $id;
        $this->is_view = $is_view;
    }

    protected function script($suuid, $label)
    {
        $table_name = array_get($this->custom_table, 'table_name');
        // create url
        $url = admin_urls("data", $table_name, $this->id, "shareClick");
        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');

        $label = esc_html(sprintf(exmtrans('common.message.confirm_execute'), ($label ?? exmtrans('common.plugin'))));

        return <<<EOT

        $('#menu_button_$suuid').off('click').on('click', function(){
            Exment.CommonEvent.ShowSwal("$url", {
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
        $table_name = array_get($this->custom_table, 'table_name');
        $url = admin_urls($this->is_view?'view':'data', $table_name, $this->id, "shareClick");
        //Admin::script($this->script($suuid, $label));

        return view('exment::tools.modal-button', [
            'suuid' => $suuid,
            'label' => $label ?? null,
            'button_class' => 'btn-warning',
            'icon' => 'fa-share',
            'url' => $url
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
