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

    public function render()
    {
        // get label
        $label = array_get($this->action, 'action_name');
        $action_id = array_get($this->action, 'id');
        $button_class = 'btn-warning';

        $url = admin_urls("data", $this->custom_table->table_name, $this->id, "actionModal");
        
        return view('exment::tools.workflow-menu-button', [
            'ajax' => $url,
            'expand' => collect(['action_id' => $action_id])->toJson(),
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
