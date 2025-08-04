<?php

namespace Exceedone\Exment\Form\Tools;

use Encore\Admin\Admin;
use Encore\Admin\Widgets\Button;

/**
 * Custom Table Menu
 */
class CustomTableRunMultiAiOcrButton extends ModalTileMenuButton
{
    protected $page_name;
    protected $custom_table;
    protected $page_name_sub;
    protected $run_multi_ai_ocr_endpoint;

    public function __construct($endpoint, $page_name, $custom_table, $page_name_sub = null)
    {
        $this->page_name = $page_name;
        $this->custom_table = $custom_table;
        $this->page_name_sub = $page_name_sub;
        $this->run_multi_ai_ocr_endpoint = $endpoint;

        Admin::script($this->script());
    }

    public function render()
    {
        $label = exmtrans('change_page_menu.ai_ocr_run');

        return <<<HTML
        <div class="btn-group pull-right" style="margin-right: 5px">
            <button id="run-multi-ai-ocr-btn"
                    class="btn btn-sm btn-success"
                    style="display:none;"
                    data-files-path="">
                <i class="fa fa-robot"></i><span class="hidden-xs"> {$label}</span>
            </button>
        </div>
        HTML;
    }

    protected function script()
    {
        $btnLabel = exmtrans('change_page_menu.ai_ocr_run');
        $successNotice = str_replace(':btn', $btnLabel, exmtrans('custom_value.import.help.import_success_multi_ai_ocr_notice'));
        $successNoticeEscaped = addslashes($successNotice);
        return <<<JS
        if (!window.__multiAiOcrListenerRegistered) {
            window.__multiAiOcrListenerRegistered = true;
            window.addEventListener('ai-ocr-multi-uploaded', function(event) {
                const btn = document.getElementById('run-multi-ai-ocr-btn');
                if (!btn) return;
                btn.setAttribute('data-files-path', event.detail.files_path);
                btn.style.display = 'inline-block';

                setTimeout(function() {
                    alert("{$successNoticeEscaped}");
                }, 500);
            });

            $(document).off('click', '#run-multi-ai-ocr-btn').on('click', '#run-multi-ai-ocr-btn', function () {
                const filesPath = this.getAttribute('data-files-path');
                if (!filesPath) {
                    alert("Upload Files not found.");
                    return;
                }

                document.body.style.cursor = 'wait';
                fetch('{$this->run_multi_ai_ocr_endpoint}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    },
                    body: JSON.stringify({ files_path: filesPath })
                })
                .then(res => res.json())
                .then(data => {
                    document.body.style.cursor = 'default';
                    if (data.message === "Multi OCR completed") {
                        const success = data.succeedOcrFilesCount || 0;
                        const failed = data.failedOcrFilesCount || 0;
                        let message = `AI-OCR completed.\nSuccessful files: \${success}\nFailed files: \${failed}`;
                        alert(message);
                        $.pjax({
                            url: window.location.href,
                            container: '#pjax-container'
                        });
                    } else {
                        alert('AI-OCR processing error.');
                    }
                })
                .catch(err => {
                    alert('OCR request error.');
                });
            });
        }
    JS;
    }
}
