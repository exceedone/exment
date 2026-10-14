<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\ConditionItems\ConditionItemBase;
use Illuminate\Support\Collection;

/**
 * Api about target table
 */
class ApiTableController extends AdminControllerTableBase
{
    use ApiTrait;

    protected $custom_table;

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
        if (!$this->custom_table) {
            return abortJson(404);
        }

        return $this->{$method}(...array_values($parameters));
    }

    // CustomColumn --------------------------------------------------
    /**
     * get table columns
     */
    // @phpstan-ignore-next-line
    public function tableColumns(Request $request)
    {
        if (($code = $this->custom_table->enableAccess()) !== true) {
            // @phpstan-ignore-next-line
            return abortJson(403, $code);
        }

        return $this->custom_columns;
    }


    /**
     * get column data by id
     *
     * @param Request $request
     * @param string $tableKey
     * @param string $column_name
     * @return CustomColumn|\Symfony\Component\HttpFoundation\Response
     */
    public function tableColumn(Request $request, $tableKey, $column_name)
    {
        return $this->responseColumn($request, CustomColumn::getEloquent($column_name, $tableKey));
    }


    // View ----------------------------------------------------

    /**
     * get view datalist
     *
     * @param Request $request
     * @param $tableKey
     * @return Collection<int, CustomView>|\Symfony\Component\HttpFoundation\Response
     */
    // @phpstan-ignore-next-line
    public function views(Request $request, $tableKey)
    {
        if (($code = $this->custom_table->enableAccess()) !== true) {
            // @phpstan-ignore-next-line
            return abortJson(403, $code);
        }

        $query = CustomView::where('custom_table_id', $this->custom_table->id);

        // set filter
        $req = $request->all();
        $keys = ['view_type', 'view_kind_type', 'view_view_name'];

        foreach ($keys as $key) {
            if (!is_null($v = array_get($req, $key))) {
                $query->where($key, $v);
            }
        }

        return $query->get();
    }



    /**
     * get filter condition
     */
    // @phpstan-ignore-next-line
    public function getFilterCondition(Request $request)
    {
        $item = $this->getConditionItem($request, $request->get('q'));
        if (!isset($item)) {
            return [];
        }
        return $item->getFilterCondition();
    }

    /**
     * get filter condition
     */
    // @phpstan-ignore-next-line
    public function getFilterValue(Request $request)
    {
        $item = $this->getConditionItem($request, $request->get('target'), $request->get('filter_kind'));
        if (!isset($item)) {
            return [];
        }
        return $item->getFilterValueAjax($request->get('cond_key'), $request->get('cond_name'), boolval($request->get('show_condition_key')));
    }

    // @phpstan-ignore-next-line
    protected function getConditionItem(Request $request, $target, $filterKind = null)
    {
        $item = ConditionItemBase::getItemByRequest($this->custom_table, $target);
        if (is_null($item)) {
            return null;
        }

        $elementName = str_replace_ex($request->get('replace_search', 'condition_key'), $request->get('replace_word', 'condition_value'), $request->get('cond_name'));
        $label = exmtrans('condition.condition_value');
        $item->setElement($elementName, 'condition_value', $label);
        if (isset($filterKind)) {
            $item->filterKind($filterKind);
        }

        return $item;
    }

    /**
     * get updateTypeCondition condition
     */
    // @phpstan-ignore-next-line
    public function getOperationUpdateType(Request $request)
    {
        $item = $this->getConditionItem($request, $request->get('q'));
        if (!isset($item)) {
            return [];
        }
        return $item->getOperationUpdateType();
    }

    /**
     * get filter condition
     */
    // @phpstan-ignore-next-line
    public function getOperationFilterValue(Request $request)
    {
        $item = $this->getConditionItem($request, $request->get('target'), $request->get('filter_kind'));
        if (!isset($item)) {
            return [];
        }
        return $item->getOperationFilterValueAjax($request->get('cond_key'), $request->get('cond_name'), boolval($request->get('show_condition_key')));
    }

    /**
     * Create one record of this table from a single label, and hand back the id.
     *
     * Backlog puts a + beside カテゴリー and マイルストーン because the moment somebody
     * needs a category that does not exist yet is the moment they are filling in an
     * issue. Sending them to a master screen means abandoning the half-typed issue,
     * so in practice they pick the wrong category instead.
     *
     * Only the label is asked for: anything else the master needs can be filled in
     * later on its own screen, and asking for it here would rebuild the very form
     * this is meant to avoid.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    // @phpstan-ignore-next-line
    public function quickAdd(Request $request)
    {
        if (($code = $this->custom_table->enableCreate(true)) !== true) {
            // @phpstan-ignore-next-line
            return abortJson(403, $code);
        }

        $validator = \Validator::make($request->all(), [
            'label' => 'required|string|max:255',
        ]);
        if ($validator->fails()) {
            return abortJson(400, ['errors' => $this->getErrorMessages($validator)]);
        }

        $label = trim(strval($request->get('label')));

        $column = $this->getQuickAddColumn();
        if (!isset($column)) {
            return abortJson(400, ['errors' => [exmtrans('custom_value.message.quickadd_no_column')]]);
        }

        // Re-using a row that already carries this label is the behaviour somebody
        // expects from a master list; creating a second "画面・UI" would quietly
        // split every report that groups by it.
        $existing = $this->custom_table->getValueModel()->newQuery()
            ->where($column->getQueryKey(), $label)
            ->first();

        if (isset($existing)) {
            return response()->json([
                'id' => $existing->id,
                'label' => $existing->label,
                'created' => false,
            ]);
        }

        $custom_value = $this->custom_table->getValueModel();
        $custom_value->setValue($column->column_name, $label);
        $custom_value->save();

        return response()->json([
            'id' => $custom_value->id,
            'label' => $custom_value->label,
            'created' => true,
        ]);
    }

    /**
     * Which column a quick-added label goes into: the 見出し列, because that is what
     * the select box will show back.
     *
     * @return \Exceedone\Exment\Model\CustomColumn|null
     */
    // @phpstan-ignore-next-line
    protected function getQuickAddColumn()
    {
        $labels = $this->custom_table->getLabelColumns();

        if ($labels instanceof Collection) {
            $first = $labels->first();
            if (isset($first)) {
                $column = CustomColumn::getEloquent(array_get($first->options, 'table_label_id'));
                if (isset($column)) {
                    return $column;
                }
            }
        }

        return $this->custom_table->custom_columns_cache
            ->filter(function ($custom_column) {
                return isMatchString($custom_column->column_type, ColumnType::TEXT);
            })
            ->first();
    }
}
