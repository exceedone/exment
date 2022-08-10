<?php

namespace Exceedone\Exment\Form\Tools;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Model\CustomOperation;

/**
 * Operation menu button.
 */
class OperationButton
{
    protected $operation;
    protected $custom_table;
    protected $id;
    // set this operation type
    protected $operation_type;

    public function __construct($listButton, $custom_table, $id = null)
    {
        if ($listButton instanceof CustomOperation) {
            $this->operation = $listButton;
            $this->operation_type = $this->operation->operation_type;
        } else {
            $this->operation = array_get($listButton, 'operation');
            $this->operation_type = array_get($listButton, 'operation_type');
        }
        $this->custom_table = $custom_table;
        $this->id = $id;
    }

    protected function script($suuid, $label)
    {
        $table_name = array_get($this->custom_table, 'table_name');
        // create url
        if (isset($this->id)) {
            $url = admin_urls("data", $table_name, $this->id, "operationClick");
        } else {
            $url = admin_urls("data", $table_name, "operationClick");
        }
        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');

        $label = esc_html($label);
        $text = sprintf(exmtrans('common.message.confirm_execute'), ($label ?? exmtrans('change_page_menu.custom_operation')));
        $operation_type = arrayToString($this->operation_type);
        return <<<EOT

        $('#menu_button_$suuid').off('click').on('click', function(){
            let select_ids = $('.column-__row_selector__').length > 0 ? $.admin.grid.selected() : null;
            Exment.CommonEvent.ShowSwal("$url", {
                title: "$label",
                text: "$text",
                confirm:"$confirm",
                cancel:"$cancel",
                data: {
                    suuid:"$suuid",
                    operation_type: '$operation_type',
                    select_ids: select_ids
                }
            });
        });
EOT;
    }

    protected function scriptModal($suuid)
    {
        $table_name = array_get($this->custom_table, 'table_name');
        // create url
        if (isset($this->id)) {
            $url = admin_urls("data", $table_name, $this->id, "operationModal");
        } else {
            $url = admin_urls("data", $table_name, "operationModal");
        }
        return <<<EOT

        $('#menu_button_$suuid').off('click').on('click', function(){
            Exment.ModalEvent.ShowModal($("#modal-form-$suuid"), '$url', {
                'suuid': '$suuid'
            });
            return;
        });
EOT;
    }

    public function render()
    {
        $label = array_get($this->operation, 'options.button_label') ??
            array_get($this->operation, 'operation_name');

        // get suuid
        $suuid = array_get($this->operation, 'suuid');

        // get operation input fields
        $operation_input_columns = $this->operation->custom_operation_input_columns ?? [];
        if (count($operation_input_columns) > 0) {
            $script = $this->scriptModal($suuid);
        } else {
            $script = $this->script($suuid, $label);
        }
        Admin::script($script);

        // get button_class
        $button_class = array_get($this->operation, 'options.button_class');
        if (!isset($button_class)) {
            $button_class = 'btn-default';
        }

        return view('exment::tools.operation-menu-button', [
            'suuid' => $suuid,
            'label' => $label ?? null,
            'button_class' => $button_class,
            'icon' => array_get($this->operation, 'options.button_icon') ?? '',
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
