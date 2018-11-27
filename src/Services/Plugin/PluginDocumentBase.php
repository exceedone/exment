<?php
namespace Exceedone\Exment\Services\Plugin;

use Exceedone\Exment\Services\DocumentPdfService;
use Exceedone\Exment\Services\DocumentExcelService;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\File as ExmentFile;
use Illuminate\Support\Facades\File;

abstract class PluginDocumentBase
{
    use PluginBase;
    
    protected $custom_table;
    protected $custom_value;
    protected $document_value;

    public function __construct($custom_table, $custom_value_id)
    {
        $this->custom_table = $custom_table;
        $this->custom_value = getModelName($custom_table)::find($custom_value_id);
    }

    /**
     * Create document
     */
    public function execute()
    {
        $table_name = $this->custom_table->table_name;
        
        // execute prependExecute
        $this->executing();

        // create pdf
        list($template_path, $output_filename) = $this->getDocumentInfo();
        $service = new DocumentExcelService($this->custom_value, $template_path, $output_filename);
        $service->makeExcel();

        // set path and file info
        $path = $service->getFilePath();
        $file = ExmentFile::saveFileInfo($path);

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
        $json = json_decode(File::get($document_json_path), true);

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
                'message' => 'Create Document Success!!', //TODO:trans
            ]);
        }
        return ([
            'result'  => false,
            'message' => 'Create Document failure', //TODO:trans
        ]);
    }

    /**
     * get document info.
     * first, template xlsx fullpath.
     * second, output file name.
     */
    protected function getDocumentInfo()
    {
        $reflector = new \ReflectionClass(get_class($this));
        $dir_path = dirname($reflector->getFileName());
        // read config.json
        $document_json_path = path_join($dir_path, 'config.json');
        $json = json_decode(File::get($document_json_path), true);

        // return "filename" value
        // if not exists, document and date time
        return [
            path_join($dir_path, 'document.xlsx'),
            array_get($json, "filename", "document".\Carbon\Carbon::now()->format('YmdHis'))
        ];
    }
    
    /**
     * execute before creating document
     */
    //abstract protected function executing();
    
    /**
     * execute after creating document
     */
    //abstract protected function executed();
}
