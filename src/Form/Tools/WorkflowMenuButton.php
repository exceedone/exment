<?php

namespace Exceedone\Exment\Form\Tools;

use Encore\Admin\Facades\Admin;

/**
 * Plugin menu button.
 */
class WorkflowMenuButton
{
    protected $action;
    protected $custom_table;
    protected $id;
    
    public function __construct($action, $custom_table, $id = null)
    {
        $this->action = $action;
        $this->custom_table = $custom_table;
        $this->id = $id;
    }

    protected function script($action_id, $label)
    {
        $table_name = array_get($this->custom_table, 'table_name');
        // create url
        $url = admin_urls("data", $table_name, $this->id, "actionClick");

        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');

        $label = esc_html(sprintf(exmtrans('common.message.confirm_execute'), ($label ?? exmtrans('common.action'))));

        return <<<EOT

        $('#menu_button_$action_id').off('click').on('click', function(){
            Exment.CommonEvent.ShowSwal("$url", {
                title: "$label",
                confirm:"$confirm",
                cancel:"$cancel",
                data: {
                    action_id:"$action_id"
                }
            });
        });
EOT;
    }

    public function render()
    {
        // get label
        $label = array_get($this->action, 'action_name');
        $action_id = array_get($this->action, 'id');
        $button_class = 'btn-warning';

        // create script
        Admin::script($this->script($action_id, $label));

        return view('exment::tools.plugin-menu-button', [
            'uuid' => $action_id,
            'button_class' => $button_class,
            'label' => $label ?? null,
            'icon' => 'fa-check-square',
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
