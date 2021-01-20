<?php
namespace Exceedone\Exment\Services\FormSetting\FormBlock;

use Encore\Admin\Form;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\SystemColumn;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 */
abstract class BlockBase
{
    /**
     * based table
     *
     * @var CustomTable
     */
    protected $custom_table;

    /**
     * This form block's target table
     *
     * @var CustomTable
     */
    protected $target_table;

    /**
     * @var CustomFormBlock
     */
    protected $custom_form_block;


    public function __construct(CustomFormBlock $custom_form_block, CustomTable $custom_table)
    {
        $this->custom_form_block = $custom_form_block;
        $this->custom_table = $custom_table;
        $this->target_table = $custom_form_block->target_table;
    }

    public static function make(CustomFormBlock $custom_form_block, CustomTable $custom_table) : BlockBase
    {
        switch (array_get($custom_form_block, 'form_block_type', FormBlockType::DEFAULT)) {
            case FormBlockType::DEFAULT:
                return new DefaultBlock($custom_form_block, $custom_table);
        }

        return null;
    }


    /**
     * Get items for display
     *
     * @return array
     */
    public function getItemsForDisplay() : array
    {
        return [
            'id' => $this->custom_form_block->id ?? null,
            'form_block_type' => $this->custom_form_block->form_block_type ?? FormBlockType::DEFAULT,
            'available' => $this->custom_form_block->available ?? 1,
            'form_block_view_name' => $this->custom_form_block->form_block_view_name ?? $this->custom_form_block->label ?? null,
            'form_block_target_table_id' => $this->custom_form_block->form_block_target_table_id ?? $this->target_table->id ?? $this->custom_table->id ?? null,
            'label' => static::getBlockLabelHeader(),
            'header_name' => $this->getHtmlHeaderName(),
            'suggests' => $this->getSuggestItems(),
            'custom_form_columns' => [],
            'select_table_columns' => $this->getSelectTableColumns()->toJson(),
        ];
    }


    /**
     * Get suggest items
     * TODO:refactor
     *
     * @return Collection
     */
    public function getSuggestItems()
    {
        $suggests = collect();

        // get columns by form_block_target_table_id.
        $custom_columns = $this->target_table->custom_columns_cache->toArray();
        $custom_form_columns = [];
        
        // set VIEW_COLUMN_SYSTEM_OPTIONS as header and footer
        $system_columns_header = SystemColumn::getOptions(['header' => true]) ?? [];
        $system_columns_footer = SystemColumn::getOptions(['footer' => true]) ?? [];

        $loops = [
            ['form_column_type' => FormColumnType::COLUMN , 'columns' => $custom_columns],
        ];

        // loop header, custom_columns, footer
        foreach ($loops as $loop) {
            // get array items
            $form_column_type = array_get($loop, 'form_column_type');
            $columns = array_get($loop, 'columns');
            // loop each column
            foreach ($columns as &$custom_column) {
                $has_custom_forms = false;
                // check $this->custom_form_block->custom_form_columns. if $custom_column has $this->custom_form_columns, add parameter has_custom_forms.
                // if has_custom_forms is true, not show display default.
                if (collect(array_get($this->custom_form_block, 'custom_form_columns', []))->first(function ($custom_form_column) use ($custom_column, $form_column_type) {
                    if (boolval(array_get($custom_form_column, 'delete_flg'))) {
                        return false;
                    }
                    return array_get($custom_form_column, 'form_column_type') == $form_column_type && array_get($custom_form_column, 'form_column_target_id') == array_get($custom_column, 'id');
                })) {
                    $has_custom_forms = true;
                }

                // re-set column
                $custom_column = [
                    'column_name' => array_get($custom_column, 'column_name'),
                    'column_view_name' => array_get($custom_column, 'column_view_name'),
                    'column_type' => array_get($custom_column, 'column_type'),
                    'form_column_type' => $form_column_type,
                    'form_column_target_id' => array_get($custom_column, 'id'),
                    'has_custom_forms' => $has_custom_forms,
                    'required' => boolval(array_get($custom_column, 'required')),
                ];

                $custom_form_columns[] = $custom_column;
            }
        }
    
        // add header name
        foreach ($custom_form_columns as &$custom_form_column) {
            $header_column_name = '[custom_form_columns]['
            .(isset($custom_form_column['id']) ? $custom_form_column['id'] : 'NEW__'.make_uuid())
            .']';
            $custom_form_column['header_column_name'] = $header_column_name;
            $custom_form_column['toggle_key_name'] = make_uuid();
        }

        $suggests->push([
            'label' => exmtrans('custom_form.suggest_column_label'),
            'custom_form_columns' => $custom_form_columns,
            'clone' => false,
            'form_column_type' => FormColumnType::COLUMN,
        ]);

        
        // set free html
        $custom_form_columns  = [];
        foreach (FormColumnType::getOptions() as $id => $type) {
            $header_column_name = '[custom_form_columns][NEW__'.make_uuid().']';
            $custom_form_columns[] = [
                'id' => null,
                'column_view_name' => exmtrans("custom_form.form_column_type_other_options.".array_get($type, 'column_name')),
                'form_column_type' => FormColumnType::OTHER,
                'required' => false,
                'form_column_target_id' => $id,
                'header_column_name' =>$header_column_name,
                'toggle_key_name' => make_uuid(),
            ];
        }
        $suggests->push([
            'label' => exmtrans('custom_form.suggest_other_label'),
            'custom_form_columns' => $custom_form_columns,
            'clone' => true,
            'form_column_type' => FormColumnType::OTHER,
        ]);

        return $suggests;
    }

    /**
     * Get select table's columns in this block.
     *
     * @return Collection
     */
    public function getSelectTableColumns() : Collection
    {
        if (!$this->target_table) {
            return collect();
        }

        $custom_columns = $this->target_table->custom_columns_cache->filter(function ($custom_column) {
            return ColumnType::isSelectTable(array_get($custom_column, 'column_type'));
        });
            
        // if form block type is 1:n or n:n, get parent tables columns too.
        if ($this instanceof RelationBase) {
            $custom_columns = $custom_columns->merge(
                $this->custom_table->custom_columns_cache,
            );
        }

        $result = [];
        foreach ($custom_columns as $custom_column) {
            // if not have array_get($custom_column, 'options.select_target_table'), conitnue
            if (!isset($custom_column)) {
                continue;
            }

            $target_table = $custom_column->select_target_table;
            if (!isset($target_table)) {
                return $result;
            }

            // get custom table
            $custom_table = $custom_column->custom_table_cache;
            // set table name if not $form_block_target_table_id and custom_table_eloquent's id
            $form_block_target_table_id = array_get($this->custom_form_block, 'form_block_target_table_id');
            if (!isMatchString($custom_table->id, $form_block_target_table_id)) {
                $select_table_column_name = sprintf('%s:%s', $custom_table->table_view_name, array_get($custom_column, 'column_view_name'));
            } else {
                $select_table_column_name = array_get($custom_column, 'column_view_name');
            }
            // get select_table, user, organization columns
            $result[array_get($custom_column, 'id')] = $select_table_column_name;
        }

        return collect($result);
    }


    /**
     * Get form columns from $this->custom_form_block.
     * If first request, set from database.
     * If not (ex. validation error), set from request value
     *
     * @return Collection
     */
    public function getFormColumns() : Collection
    {
        // get custom_form_blocks from request
        $req_custom_form_blocks = old('custom_form_blocks');
        if (!isset($req_custom_form_blocks)
            || !isset($req_custom_form_blocks[$this->custom_form_block['id']])
            || !isset($req_custom_form_blocks[$this->custom_form_block['id']]['custom_form_columns'])
        ) {
            return collect(array_get($this->custom_form_block, 'custom_form_columns') ?? []);
        }

        $custom_form_columns = $req_custom_form_blocks[$this->custom_form_block['id']]['custom_form_columns'];
        return collect($custom_form_columns)->map(function ($custom_form_column, $id) {
            $custom_form_column['id'] = $id;
            return collect($custom_form_column);
        });
    }


    /**
     * Get html header name
     *
     * @return void
     */
    protected function getHtmlHeaderName()
    {
        return 'custom_form_blocks['
            .(isset($this->custom_form_block['id']) ? $this->custom_form_block['id'] : 'NEW__'.make_uuid())
            .']';
    }
    
    abstract public static function getBlockLabelHeader();
}
