<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Linkage;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ValueType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\ValidateCalledType;
use Exceedone\Exment\Services\DataImportExport\DataImportExportService;
use Carbon\Carbon;
use Validator;

/**
 * Public form Api about target table's data
 */
class PublicFormApiDataController extends AdminControllerTableBase
{
    use ApiTrait, ApiDataTrait;

    protected $custom_table;

    /**
     * Execute an action on the controller.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function callAction($method, $parameters)
    {
        if (!$this->custom_table) {
            return abortJson(404);
        }
        
        return call_user_func_array([$this, $method], $parameters);
    }

    /**
     * find data by id
     * use select Changedata
     * @param mixed $id
     * @return mixed
     */
    public function dataFind(Request $request, $uuid, $tableKey, $id)
    {
        return $this->_dataFind($request, $id);
    }

    /**
     * get table columns data. seletcting column, and search.
     *
     * @param Request $request
     * @param string $tableKey
     * @param string $column_name
     * @return Response
     */
    public function columnData(Request $request, $uuid, $tableKey, $column_name)
    {
        return $this->_columnData($request, $column_name);
    }
}
