<?php
namespace Exceedone\Exment\Services\Plugin;

use Exceedone\Exment\Services\DocumentExcelService;
use Exceedone\Exment\Model\File as ExmentFile;
use Illuminate\Support\Facades\File;

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
        $file = ExmentFile::saveFileInfo($path, null, null, true);

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
                'toastr' => 'Create Document Success!!', //TODO:trans
            ]);
        }
        return ([
            'result'  => false,
            'toastr' => 'Create Document failure', //TODO:trans
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
        if(!File::exists($document_json_path)){
            $filename = $default_document_name;
        }else{
            $json = json_decode(File::get($document_json_path), true);
            $filename = array_get($json, "filename", $default_document_name);   
        }
        // return "filename" value
        // if not exists, document and date time
        return [
            path_join($dir_path, 'document.xlsx'),
            $filename
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
