<?php

namespace Exceedone\Exment\Form\Tools;

use Illuminate\Contracts\Support\Renderable;
use Encore\Admin\Facades\Admin;

/**
 * Copy menu button.
 */
class CopyMenuButton implements Renderable
{
    protected $copy;
    protected $custom_table;
    protected $id;

    public function __construct($copy, $custom_table, $id = null)
    {
        $this->copy = $copy;
        $this->custom_table = $custom_table;
        $this->id = $id;
    }

    protected function scriptSwal($uuid, $label)
    {
        $table_name = array_get($this->custom_table, 'table_name');
        // create url
        if (isset($this->id)) {
            $url = admin_urls("data", $table_name, $this->id, "copyClick");
        } else {
            $url = admin_urls("data", $table_name, "copyClick");
        }
        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');

        $label = esc_html($label);
        $label = sprintf(exmtrans('common.message.confirm_execute'), ($label ?? exmtrans('common.copy')));
        return <<<EOT

        $('#menu_button_$uuid').off('click').on('click', function(){
            Exment.CommonEvent.ShowSwal("$url", {
                title: "$label",
                confirm:"$confirm",
                cancel:"$cancel",
                data: {
                    'uuid': '$uuid',
                },
            });
        });
EOT;
    }

    protected function scriptModal($uuid)
    {
        $table_name = array_get($this->custom_table, 'table_name');
        // create url
        if (isset($this->id)) {
            $url = admin_urls("data", $table_name, $this->id, "copyModal");
        } else {
            $url = admin_urls("data", $table_name, "copyModal");
        }
        return <<<EOT

        $('#menu_button_$uuid').off('click').on('click', function(){
            Exment.ModalEvent.ShowModal($("#modal-form-$uuid"), '$url', {
                'uuid': '$uuid'
            });
            return;
        });
EOT;
    }

    /**
     * render html
     *
     * @return string
     */
    public function render()
    {
        // get label
        if (!is_null(array_get($this->copy, 'options.label'))) {
            $label = array_get($this->copy, 'options.label');
        } else {
            $label = exmtrans('common.copy');
        }

        // get uuid
        $uuid = array_get($this->copy, 'suuid');

        // get copy input fields
        $copy_input_columns = $this->copy->custom_copy_input_columns ?? [];
        if (count($copy_input_columns) > 0) {
            $script = $this->scriptModal($uuid);
        } else {
            $script = $this->scriptSwal($uuid, $label);
        }
        Admin::script($script);

        // get button_class
        $button_class = array_get($this->copy, 'options.button_class');
        if (!isset($button_class)) {
            $button_class = 'btn-default';
        }

        return view('exment::tools.plugin-menu-button', [
            'uuid' => $uuid,
            'label' => $label ?? null,
            'button_class' => $button_class,
            'icon' => array_get($this->copy, 'options.icon') ?? '',
        ])->render();
    }
}
