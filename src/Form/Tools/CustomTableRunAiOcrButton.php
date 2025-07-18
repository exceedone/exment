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
                <i class="fa fa-robot"></i><span class="hidden-xs"> {$label}</span>
            </button>
        </div>
        HTML;
    }

    protected function script()
    {
        return <<<JS
        function normalizeDate(value) {
            // YY-MM-DD
            // 2022年8月31日
            let match = value.match(/(\d{4})年(\d{1,2})月(\d{1,2})日/);
            if (match) {
                const year = match[1];
                const month = match[2].padStart(2, '0');
                const day = match[3].padStart(2, '0');
                return `\${year}-\${month}-\${day}`;
            }

            // YYYY/MM/DD or YYYY.MM.DD or YYYY-MM-DD
            match = value.match(/(\d{4})\/\.\-\/\.\-/);
            if (match) {
                const year = match[1];
                const month = match[2].padStart(2, '0');
                const day = match[3].padStart(2, '0');
                return `\${year}-\${month}-\${day}`;
            }

            // DD-MM-YYYY or DD/MM/YYYY
            match = value.match(/(\d{1,2})\/\-\/\-/);
            if (match) {
                const day = match[1].padStart(2, '0');
                const month = match[2].padStart(2, '0');
                const year = match[3];
                return `\${year}-\${month}-\${day}`;
            }

            return value;
        }

        function normalizeTime(value) {
            value = value.trim();
            // hh:mm:ss
            let match = value.match(/^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/);
            if (match) {
                const hours = match[1].padStart(2, '0');
                const minutes = match[2].padStart(2, '0');
                const seconds = (match[3] || '00').padStart(2, '0');
                return `\${hours}:\${minutes}:\${seconds}`;
            }

            return value;
        }

        function normalizeDateTime(value) {
            // YY-MM-DD yy:mm:ss
            value = value.trim();
            let parts = value.split(/\s+/);
            if (parts.length === 2) {
                const datePart = normalizeDate(parts[0]);
                const timePart = normalizeTime(parts[1]);
                return `\${datePart} \${timePart}`;
            }

            if (value.includes('年') || value.includes('/') || value.includes('-') || value.includes('.')) {
                return normalizeDate(value);
            }

            if (value.includes(':')) {
                return normalizeTime(value);
            }

            return value;
        }

        function normalizeEmail(value) {
            value = value.trim().toLowerCase();
            value = value.replace(/\s+/g, '');
            value = value.replace(/[（）]/g, '');
            value = value.replace(/\[at\]|\(at\)|＠/g, '@');

            const emailPattern = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-z]{2,}$/i;
            if (emailPattern.test(value)) {
                return value;
            }

            return '';
        }

        function normalizeUrl(value) {
            value = value.trim().replace(/\s+/g, '');

            if (!/^https?:\/\//i.test(value)) {
                value = 'http://' + value;
            }

            const urlPattern = /^https?:\/\/[a-zA-Z0-9\-._~%]+(?:\.[a-zA-Z]{2,})+(?:\/[^\s]*)?$/;
            if (urlPattern.test(value)) {
                return value;
            }

            return '';
        }

        window.addEventListener('ai-ocr-uploaded', function(event) {
            const btn = document.getElementById('run-ai-ocr-btn');
            if (!btn) return;
            btn.setAttribute('data-file-path', event.detail.files_path);
            btn.style.display = 'inline-block';

            const input = document.querySelector('input[name="value[ai_ocr_temp_path]"]');
            if (input) {
                input.value = event.detail.files_path;
            }

            const label = document.querySelector('.ai-ocr-uploaded-label');
            if (label) {
                label.style.display = 'block';
            }
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
                        case 'textarea':
                            const textareaField = document.querySelector(`[name="value[\${key}]"]`);
                            if (textareaField) {
                                textareaField.value = value;
                            }
                            break;
                        case 'url':
                            const urlField = document.querySelector(`[name="value[\${key}]"]`);
                            if (urlField) {
                                urlField.value = normalizeUrl(value);
                            }
                            break;
                        case 'email':
                            const emailField = document.querySelector(`[name="value[\${key}]"]`);
                            if (emailField) {
                                emailField.value = normalizeEmail(value);
                            }
                            break;
                        case 'text':
                            const inputField = document.querySelector(`[name="value[\${key}]"]`);
                            if (inputField) {
                                inputField.value = value;
                            }
                            break;
                        case 'decimal':
                        case 'currency':
                        case 'integer':
                            const numberField = document.querySelector(`[name="value[\${key}]"]`);
                            if (numberField) {
                                numberField.value = value.replace(/[^\d.-]/g, '');
                            }
                            break;
                        case 'time':
                            const timeField = document.querySelector(`[name="value[\${key}]"]`);
                            if (timeField) {
                                timeField.value = normalizeTime(value);
                            }
                            break;
                        case 'datetime':
                            const datetimeField = document.querySelector(`[name="value[\${key}]"]`);
                            if (datetimeField) {
                                datetimeField.value = normalizeDateTime(value);
                            }
                            break;
                        case 'date':
                            const dateField = document.querySelector(`[name="value[\${key}]"]`);
                            if (dateField) {
                                dateField.value = normalizeDate(value);
                            }
                            break;
                        default:
                            break;
                        }
                    }
                } else {
                    alert('AI-OCR processing error.');
                }
            })
            .catch(err => {
                alert('AI-OCR request error.');
            });
        });
        JS;
    }
}
