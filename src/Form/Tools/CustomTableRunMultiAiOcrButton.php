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
        $successfulFilesLabel = json_encode(exmtrans('custom_table.ai_ocr.successful_files'), JSON_UNESCAPED_UNICODE);
        $failedFilesLabel = json_encode(exmtrans('custom_table.ai_ocr.failed_files'), JSON_UNESCAPED_UNICODE);
        $failedFileListLabel = json_encode(exmtrans('custom_table.ai_ocr.failed_file_list'), JSON_UNESCAPED_UNICODE);
        $errorProcessingFailed = json_encode(exmtrans('custom_table.ai_ocr.error_processing_failed'), JSON_UNESCAPED_UNICODE);

        return <<<JS
        if (!window.__multiAiOcrListenerRegistered) {
            window.__multiAiOcrListenerRegistered = true;

            window.addEventListener('ai-ocr-multi-uploaded', function(event) {
                const filesPath = event.detail.files_path;
                if (!filesPath) {
                    return;
                }

                // Run OCR
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
                    if (data.succeedOcrFilesCount !== undefined) {
                        const successfulFilesLabel = {$successfulFilesLabel};
                        const failedFilesLabel = {$failedFilesLabel};
                        const failedFileListLabel = {$failedFileListLabel};
                        const success = data.succeedOcrFilesCount || 0;
                        const failed = data.failedOcrFilesCount || 0;
                        const failedFileList = data.failedOcrFileNameList || '';

                        let message = `\${data.message}\\n\${successfulFilesLabel}: \${success}\\n\${failedFilesLabel}: \${failed}`;
                        if (failed > 0) {
                            message += `\\n\${failedFileListLabel}:\\n\${failedFileList}`;
                        }
                        alert(message);

                        $.pjax({
                            url: window.location.href,
                            container: '#pjax-container'
                        });
                    } else {
                        alert(data.message);
                    }
                })
                .catch(err => {
                    const errorProcessingFailed = {$errorProcessingFailed};
                    document.body.style.cursor = 'default';
                    alert(errorProcessingFailed);
                });
            });
        }
        JS;
    }
}
