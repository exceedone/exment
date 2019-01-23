<?php

namespace Exceedone\Exment\Services\DataImportExport;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Exceedone\Exment\Services\DataImportExport\Formats\FormatBase;

trait ImportExportTrait
{
    protected $format;
    /**
     * export and import action.
     */
    protected $action;

    public function format($format = null){
        if(!func_num_args()){
            return $this->format;
        }

        $this->format = static::getFormat($format);

        return $this;
    }

    public static function getFormat($args = []){
        if($args instanceof FormatBase){
            return $args;
        }
        
        if($args instanceof UploadedFile){
            $format = $args->extension();
        }
        elseif(array_has($args, 'format')){
            $format = array_get($args, 'format');
        }else{
            $format = app('request')->input('format');
        }

        switch ($format) {
            case 'excel':
            case 'xlsx':
                return new Formats\Xlsx();
            default:
                return new Formats\Csv();
        }
    }

    public function action($action){
        $this->action = $action;

        return $this;
    }
}
