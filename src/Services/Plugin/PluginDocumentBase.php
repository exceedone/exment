<?php
namespace Exceedone\Exment\Services\Plugin;
use Exceedone\Exment\Services\DocumentPdfService;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\File as ExmentFile;
use Illuminate\Support\Facades\File;

abstract class PluginDocumentBase {
    use PluginBase;
    
    protected $custom_table;
    protected $custom_value;
    protected $document_value;

    public function __construct($custom_table, $custom_value_id){
        $this->custom_table = $custom_table;
        $this->custom_value = getModelName($custom_table)::find($custom_value_id);
    }

    /**
     * Create document
     */
    public function execute(){
        $table_name = $this->custom_table->table_name;
        // get document items from json
        $documentItem = $this->getDocumentItem();

        // execute prependExecute
        $this->executing();

        // create pdf
        $service = new DocumentPdfService($this->custom_value, array_get($documentItem, 'info', []), array_get($documentItem, 'items', []));
        $service->makeContractPdf();

        // save
        $document_attachment_file = $service->getPdfPath();
        // save pdf
        $file = $this->savePdfInServer($document_attachment_file, $service);
        $filename = $service->getPdfFileName();

        // save Document Model
        $modelname = getModelName(Define::SYSTEM_TABLE_NAME_DOCUMENT);
        $document_model = new $modelname;
        $document_model->parent_id = $this->custom_value->id;
        $document_model->parent_type = $this->custom_table->table_name;
        $document_model->setValue([
            'file_uuid' => $file->uuid,
            'document_name' => $filename,
        ]);
        $document_model->save();

        // set document value
        $this->document_value = $document_model;

        // execute appendExecute
        $this->executed();

        // 
        admin_toastr('Create Success!');
        return response('Success');
    }

    protected function getDocumentItem(){
        // get dir base path
        $reflector = new \ReflectionClass(get_class($this));
        $dir_path = dirname($reflector->getFileName());
        // read document.json
        $document_json_path = path_join($dir_path, 'document.json');
        $json = json_decode(File::get($document_json_path), true);

        return $json;
    }

    /**
     * save pdf and get pdf fullpath
     * @param $response
     * @param $service
     */
    protected function savePdfInServer($path, $service)
    {
        $path = ExmentFile::put('admin', $path, $service->outputPdf());
        // save file
        $file = ExmentFile::getData($path);
        return $file;
    }
    
    /**
     * execute before creating document
     */
    abstract protected function executing();
    
    /**
     * execute after creating document
     */
    abstract protected function executed();
}