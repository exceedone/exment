<?php

namespace Exceedone\Exment\Grid\Tools;

use Encore\Admin\Grid\Tools\BatchAction;

class BatchUpdate extends BatchAction
{
    /**
     * @var mixed
     */
    protected $operation;

    /**
     * Create a new Tools instance.
     *
     * @param mixed $operation
     */
    public function __construct($operation)
    {
        $this->operation = $operation;
    }

    /**
     * Script of batch delete action.
     *
     * @return string
     */
    public function script()
    {
        $suuid = $this->operation->suuid;

        // get operation input fields
        $operation_input_columns = $this->operation->custom_operation_input_columns ?? [];

        if (count($operation_input_columns) > 0) {
            return $this->scriptModal($suuid);
        } else {
            return $this->scriptSwal($suuid);
        }
    }

    /**
     * Set title for this action.
     *
     * @param string $title
     *
     * @return static
     */
    public function setTitle($title)
    {
        $this->title = esc_html($title);

        return $this;
    }

    /**
     * Generate script for Swal dialog.
     *
     * @param string $suuid
     * @return string
     */
    protected function scriptSwal($suuid)
    {
        $url = url($this->resource);

        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');

        $label = $this->operation->getOption('button_label') ?? $this->operation->operation_name;
        $label = esc_html($label);
        $text = exmtrans('common.message.confirm_execute', $label);

        return <<<EOT

$('{$this->getElementClass()}').on('click', function() {
    var url = '{$url}/operationClick';
    Exment.CommonEvent.ShowSwal(url, {
        title: "$label",
        confirm:"$confirm",
        cancel:"$cancel",
        text:"$text",
        data: {
            _method:'post',
            _token:'{$this->getToken()}',
            suuid: '$suuid',
            id: $.admin.grid.selected().join(),
        },
    });
});

EOT;
    }

    /**
     * Generate script for Modal dialog.
     *
     * @param string $suuid
     * @return string
     */
    protected function scriptModal($suuid)
    {
        $url = url($this->resource);

        return <<<EOT

        $('{$this->getElementClass()}').on('click', function() {
            var url = '{$url}/operationModal';
            Exment.ModalEvent.ShowModal($("#modal-form-$suuid"), url, {
                'suuid': '$suuid',
                'id': $.admin.grid.selected().join(),
            });
            return;
        });
EOT;
    }
}
