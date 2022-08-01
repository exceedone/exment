<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\Linkage;
use Exceedone\Exment\Enums\ErrorCode;

/**
 * Public form Api about target table's data
 */
class PublicFormApiDataController extends AdminControllerTableBase
{
    use ApiDataTrait;

    protected $public_form;

    public function __construct(?CustomTable $custom_table, ?PublicForm $public_form, Request $request)
    {
        $this->public_form = $public_form;
        if ($public_form) {
            $this->custom_form = $public_form->custom_form;
        }
        parent::__construct($custom_table, $request);
    }
    /**
     * Execute an action on the controller.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function callAction($method, $parameters)
    {
        if (!$this->custom_table || !$this->public_form) {
            return abortJson(404);
        }

        return $this->{$method}(...array_values($parameters));
    }

    /**
     * find data by id
     * use select Changedata
     * @param mixed $id
     * @return mixed
     */
    public function dataFind(Request $request, $uuid, $tableKey, $id)
    {
        if (($response = $this->checkContainsCustomTableInForm($request)) !== true) {
            return $response;
        }
        return $this->_dataFind($request, $id);
    }

    /**
     * find match data for select ajax
     * @param Request $request
     * @return mixed
     */
    public function dataSelect(Request $request)
    {
        if (($response = $this->checkContainsCustomTableInForm($request)) !== true) {
            return $response;
        }
        return $this->_dataSelect($request);
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
        if (($response = $this->checkContainsCustomTableInForm($request)) !== true) {
            return $response;
        }
        return $this->_columnData($request, $column_name);
    }

    /**
     * get selected id's children values
     * *parent_select_table_id(required) : The select_table of the parent column(Changed by user) that executed Linkage. .
     * *child_select_table_id(required) : The select_table of the child column(Linkage target column) that executed Linkage.
     * *child_column_id(required) : Called Linkage target column.
     * *search_type(required) : 1:n, n:n or select_table.
     * *q(required) : id that user selected.
     */
    public function relatedLinkage(Request $request)
    {
        if (($response = $this->checkContainsCustomTableInForm($request)) !== true) {
            return $response;
        }
        return $this->_relatedLinkage($request);
    }


    /**
     * Check custom form columns in custom table
     *
     * @param Request $request
     * @return true|\Symfony\Component\HttpFoundation\Response
     */
    protected function checkContainsCustomTableInForm(Request $request)
    {
        $tablesUseds = $this->public_form->getListOfTablesUsed();
        foreach ($tablesUseds as $table) {
            if ($this->custom_table->id == $table->id) {
                return true;
            }
        }

        // if not contains custom form column in this table, return 403 error
        return abortJson(403, ErrorCode::NOT_CONTAINS_CUSTOM_FORM());
    }
}
