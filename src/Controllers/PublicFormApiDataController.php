<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\CustomColumn;
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

    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
    public function dataFind(Request $request, $uuid, $tableKey, $id)
    {
        if (($response = $this->checkContainsCustomTableInForm($request)) !== true) {
            return $response;
        }
        // The related records are not for an anonymous visitor
        $request->query->remove('children');
        $request->query->remove('parents');
        $request->request->remove('children');
        $request->request->remove('parents');

        $result = $this->_dataFind($request, $id);
        if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
            return $result;
        }

        // The visitor of a public form is not logged in, and the record is read
        // as the form's user. Only what the form needs goes back: the id and the
        // label for a select box, and the columns the form copies from the
        // record when it is selected ("changedata").
        $array = $result instanceof \Illuminate\Contracts\Support\Arrayable ? $result->toArray() : (array)$result;
        $values = (array)array_get($array, 'value', []);
        return [
            'id' => array_get($array, 'id'),
            'label' => array_get($array, 'label', ($result instanceof \Exceedone\Exment\Model\CustomValue ? $result->getLabel() : null)),
            'value' => array_intersect_key($values, array_flip($this->getChangedataColumnNames())),
        ];
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
    // @phpstan-ignore-next-line
    public function columnData(Request $request, $uuid, $tableKey, $column_name)
    {
        if (($response = $this->checkContainsCustomTableInForm($request)) !== true) {
            return $response;
        }
        // only a column that is on the form
        if (!in_array($column_name, $this->getFormColumnNames(), true)) {
            return abortJson(403, ErrorCode::NOT_CONTAINS_CUSTOM_FORM());
        }
        // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
    public function relatedLinkage(Request $request)
    {
        if (($response = $this->checkContainsCustomTableInForm($request)) !== true) {
            return $response;
        }
        // the table whose records are listed has to be used by the form too
        $child_select_table = CustomTable::getEloquent($request->get('child_select_table_id'));
        // (without it nothing is listed: _relatedLinkage answers [])
        if ($child_select_table && !$this->public_form->getListOfTablesUsed()->contains(function ($table) use ($child_select_table) {
            return $table->id == $child_select_table->id;
        })) {
            return abortJson(403, ErrorCode::NOT_CONTAINS_CUSTOM_FORM());
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

    /**
     * The columns of this table that the form copies from a selected record
     * ("changedata": the form column's changedata_column_id).
     *
     * @return array<string>
     */
    protected function getChangedataColumnNames(): array
    {
        $names = [];
        foreach ($this->eachFormColumn() as $custom_form_column) {
            $changedata_column = CustomColumn::getEloquent(array_get($custom_form_column->options, 'changedata_column_id'));
            if ($changedata_column && $changedata_column->custom_table_id == $this->custom_table->id) {
                $names[] = $changedata_column->column_name;
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * The columns of this table that are on the form.
     *
     * @return array<string>
     */
    protected function getFormColumnNames(): array
    {
        $names = [];
        foreach ($this->eachFormColumn() as $custom_form_column) {
            $custom_column = $custom_form_column->custom_column_cache;
            if ($custom_column && $custom_column->custom_table_id == $this->custom_table->id) {
                $names[] = $custom_column->column_name;
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * @return \Generator<\Exceedone\Exment\Model\CustomFormColumn>
     */
    protected function eachFormColumn()
    {
        foreach ($this->public_form->custom_form->custom_form_blocks as $custom_form_block) {
            if (!$custom_form_block->available) {
                continue;
            }
            foreach ($custom_form_block->custom_form_columns as $custom_form_column) {
                yield $custom_form_column;
            }
        }
    }
}
