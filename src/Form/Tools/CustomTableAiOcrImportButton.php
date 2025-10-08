<?php

namespace Exceedone\Exment\Form\Tools;

/**
 * Custom Table Import Button
 */
class CustomTableAiOcrImportButton extends ModalTileMenuButton
{
    protected $endpoint;
    protected $custom_table;

    public function __construct($endpoint, $custom_table)
    {
        $this->endpoint = $endpoint;
        $this->custom_table = $custom_table;

        parent::__construct([
            'label' => exmtrans('change_page_menu.ai_ocr_import'),
            'icon' => 'fa-upload',
            'button_class' => 'btn-twitter',
        ]);

        $this->modal_title = exmtrans('change_page_menu.ai_ocr_import');
    }

    public function render()
    {
        $modalUrl = url_join($this->endpoint, 'importAiOcrModal');

        return <<<HTML
        <div class="tile-menu-button d-inline-block" style="margin-right: 5px;">
            <a
                href="#"
                class="btn btn-sm {$this->button_class}"
                data-widgetmodal_url="{$modalUrl}"
                style="display: inline-flex; align-items: center;"
            >
                <i class="fa {$this->icon}" style="margin-right: 5px;"></i> {$this->label}
            </a>
        </div>
    HTML;
    }
}
