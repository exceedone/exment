<?php

namespace Exceedone\Exment\Form\Tools;

use Encore\Admin\Facades\Admin;

/**
 * notify email button.
 */
class NotifyButton
{
    protected $notify;
    protected $custom_table;
    protected $id;

    public function __construct($notify, $custom_table, $id = null)
    {
        $this->notify = $notify;
        $this->custom_table = $custom_table;
        $this->id = $id;
    }

    protected function script($suuid, $label)
    {
        $table_name = array_get($this->custom_table, 'table_name');
        // create url
        if (isset($this->id)) {
            $url = admin_urls("data", $table_name, $this->id, "notifyClick");
        } else {
            $url = admin_urls("data", $table_name, "notifyClick");
        }
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
        if (!is_null(array_get($this->notify, 'trigger_settings.notify_button_name'))) {
            $label = array_get($this->notify, 'trigger_settings.notify_button_name');
        } elseif (isset($this->notify->notify_view_name)) {
            $label = $this->notify->notify_view_name;
        }

        // get uuid
        $suuid = array_get($this->notify, 'suuid');
        $table_name = array_get($this->custom_table, 'table_name');
        $url = admin_urls("data", $table_name, $this->id, "notifyClick");
        //Admin::script($this->script($suuid, $label));

        return view('exment::tools.modal-button', [
            'suuid' => $suuid,
            'label' => $label ?? null,
            'button_class' => 'btn-info',
            'icon' => 'fa-envelope-o',
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
