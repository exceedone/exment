<?php

namespace Exceedone\Exment\Form\Tools;

use Encore\Admin\Admin;
use Encore\Admin\Widgets\Button;

/**
 * Custom Table Menu
 */
class CustomTableRunAiOcrButton extends ModalTileMenuButton
{
    protected $page_name;
    protected $custom_table;
    protected $page_name_sub;
    protected $run_ai_ocr_endpoint;

    public function __construct($endpoint, $page_name, $custom_table, $page_name_sub = null)
    {
        $this->page_name = $page_name;
        $this->custom_table = $custom_table;
        $this->page_name_sub = $page_name_sub;
        $this->run_ai_ocr_endpoint = $endpoint;

        Admin::script($this->script());
    }

    public function render()
    {
        $label = exmtrans('change_page_menu.ai_ocr_run');

        return <<<HTML
        <div class="btn-group pull-right" style="margin-right: 5px">
            <button id="run-ai-ocr-btn"
                    class="btn btn-sm btn-success"
                    style="display:none;"
                    data-file-path="">
                <i class="fa fa-robot"></i><span class="hidden-xs">&nbsp;&nbsp;{$label}</span>
            </button>
        </div>
        HTML;
    }

    protected function script()
    {
        return <<<JS
        window.addEventListener('ai-ocr-uploaded', function(event) {
            const btn = document.getElementById('run-ai-ocr-btn');
            if (!btn) return;
            btn.setAttribute('data-file-path', event.detail.files_path);
            btn.style.display = 'inline-block';
        });

        $(document).off('click', '#run-ai-ocr-btn').on('click', '#run-ai-ocr-btn', function () {
            const filePath = this.getAttribute('data-file-path');
            if (!filePath) {
                alert("Upload File not found.");
                return;
            }

            document.body.style.cursor = 'wait';
            fetch('{$this->run_ai_ocr_endpoint}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                },
                body: JSON.stringify({ file_path: filePath })
            })
            .then(res => res.json())
            .then(data => {
                document.body.style.cursor = 'default';
                if (data.message === "OCR completed" && data.result) {
                    alert('AI-OCR processed successfully.');
                    const result = data.result;
                    for (const key in result) {
                        const { value, value_type } = result[key];
                        switch (value_type) {
                        case 'editor':
                            const inputFieldTextArea = document.querySelector(`textarea[name="value[\${key}]"]`)
                            if (inputFieldTextArea) {
                                const editor = tinymce.get(inputFieldTextArea.id);
                                if (editor) {
                                    editor.setContent(value);
                                }
                            }
                            break;
                        default:
                            const inputField = document.querySelector(`[name="value[\${key}]"]`);
                            if (inputField) {
                                inputField.value = value.replace(/[^\d.-]/g, '');
                            } else {
                            }
                            break;
                        }
                    }
                } else {
                    alert('AI-OCR processing error.');
                }
            })
            .catch(err => {
                alert('OCR request error.');
            });
        });
        JS;
    }
}
