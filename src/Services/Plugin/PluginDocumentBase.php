<?php

namespace Exceedone\Exment\Services\Plugin;

use Exceedone\Exment\Services\DocumentExcelService;
use Exceedone\Exment\Model\File as ExmentFile;
use Illuminate\Support\Facades\File;
use Exceedone\Exment\Enums\FileType;

/**
 * Plugin (Document) base class
 */
abstract class PluginDocumentBase
{
    use PluginBase;

    protected $custom_table;
    protected $custom_value;
    protected $document_value;

    public function __construct($plugin, $custom_table, $custom_value_id)
    {
        $this->plugin = $plugin;
        $this->custom_table = $custom_table;

        if (isset($custom_table)) {
            $this->custom_value = $custom_table->getValueModel($custom_value_id);
        }
    }

    /**
     * Create document execution
     */
    public function execute()
    {
        $table_name = $this->custom_table->table_name;

        // execute prependExecute
        $this->executing();

        // create pdf
        list($template_path, $output_filename) = $this->getDocumentInfo();
        $service = new DocumentExcelService(
            $this->custom_value,
            $template_path,
            $output_filename
        );
        $service->setCalledCallback(function ($spreadsheet) {
            $this->called($spreadsheet);
        });
        $service->setSavingCallback(function ($spreadsheet) {
            $this->saving($spreadsheet);
        });
        $service->makeExcel();

        // set path and file info
        $file = ExmentFile::saveFileInfo(
            FileType::CUSTOM_VALUE_DOCUMENT,
            $service->getDirPath(),
            [
                'filename' => $service->getFileName(),
                'unique_filename' => $service->getUniqueFileName(),
            ]
        )->saveCustomValue($this->custom_value->id, null, $this->custom_value->custom_table);

        // save Document Model
        $document_model = $file->saveDocumentModel($this->custom_value, $service->getFileName());
        // set document value
        $this->document_value = $document_model;

        // execute appendExecute
        $this->executed();

        //
        return $this->getResponseMessage(true);
    }

    protected function getDocumentItem()
    {
        // get dir base path
        $reflector = new \ReflectionClass(get_class($this));
        $dir_path = dirname($reflector->getFileName());
        // read document.json
        $document_json_path = path_join($dir_path, 'document.json');
        $json = json_decode_ex(File::get($document_json_path), true);

        return $json;
    }

    /**
     * get response message
     */
    protected function getResponseMessage($result)
    {
        if ($result) {
            return ([
                'result'  => true,
                'toastr' => sprintf(exmtrans('common.message.success_execute')),
            ]);
        }
        return ([
            'result'  => false,
            'toastr' => sprintf(exmtrans('common.message.error_execute')),
        ]);
    }

    /**
     * get document info.
     * first, template xlsx fullpath.
     * second, output file name.
     */
    protected function getDocumentInfo()
    {
        $default_document_name = "document".\Carbon\Carbon::now()->format('YmdHis');
        $dir_path = $this->plugin->getFullPath();
        // read config.json
        $document_json_path = $this->plugin->getFullPath('config.json');
        if (!File::exists($document_json_path)) {
            $filename = $default_document_name;
        } else {
            $json = json_decode_ex(File::get($document_json_path), true);
            $filename = array_get($json, "filename", $default_document_name);
        }
        // return "filename" value
        // if not exists, document and date time
        return [
            path_join($dir_path, 'document.xlsx'),
            $filename
        ];
    }

    public function getButtonLabel()
    {
        // get label
        if (!is_null(array_get($this->plugin, 'options.label'))) {
            return array_get($this->plugin, 'options.label');
        } elseif (isset($this->plugin->plugin_view_name)) {
            return $this->plugin->plugin_view_name;
        }
    }

    /**
     * execute before creating document
     */
    protected function executing()
    {
    }

    /**
     * execute after creating document
     */
    protected function executed()
    {
    }

    /**
     * After called document service. If set custom value to document. Please override this.
     *
     * @return void
     */
    protected function called($spreadsheet)
    {
    }

    /**
     * Before saving document. If set custom value to document. Please override this.
     *
     * @return void
     */
    protected function saving($spreadsheet)
    {
    }
}
