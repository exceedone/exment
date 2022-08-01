<?php

namespace Exceedone\Exment\Form\Widgets;

/**
 *
 */
class Modal
{
    use ModalTrait;

    protected $modalBody;

    public function body($modalBody)
    {
        $this->modalBody = $modalBody;
    }

    public static function widgetModalRender()
    {
        // add modal for showmodal
        $modal = new Modal();
        $modal->modalHeader(exmtrans('custom_value.data_detail'));
        $modal->modalAttribute(['id' => 'modal-showmodal', 'data-backdrop' => 'static']);

        return $modal->render();
    }

    /**
     * Render the form.
     *
     * @return string
     */
    public function render()
    {
        $this->setModalAttributes();

        // get view
        return view('exment::widgets.modal', [
            'header' => $this->modalHeader,
            'body' => $this->modalBody,
            'modalSubmitAttributes' => 'd-none',
            'modalAttributes' => $this->convert_attribute($this->modalAttributes),
            'modalInnerAttributes' => $this->convert_attribute($this->modalInnerAttributes),
        ]);
    }
}
