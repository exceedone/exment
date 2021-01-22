<?php
namespace Exceedone\Exment\Services\FormSetting\FormBlock;

use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Services\FormSetting\FormColumn;
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

    /**
     * FormColumn list
     * @var Collection
     */
    protected $custom_form_column_items;


    public function __construct(CustomFormBlock $custom_form_block, CustomTable $custom_table)
    {
        $this->custom_form_block = $custom_form_block;
        $this->custom_table = $custom_table;
        $this->target_table = $custom_form_block->target_table;

        $this->custom_form_column_items = collect();
    }

    public static function make(CustomFormBlock $custom_form_block, CustomTable $custom_table) : BlockBase
    {
        switch (array_get($custom_form_block, 'form_block_type', FormBlockType::DEFAULT)) {
            case FormBlockType::DEFAULT:
                return new DefaultBlock($custom_form_block, $custom_table);
            case FormBlockType::ONE_TO_MANY:
                return new OneToMany($custom_form_block, $custom_table);
            case FormBlockType::MANY_TO_MANY:
                return new ManyToMany($custom_form_block, $custom_table);
        }

        return null;
    }
    


    /**
     * Get object using form_block_type
     *
     * @return self
     */
    public static function makeByParams($form_block_type, $form_block_target_table_id) : BlockBase
    {
        $form_block = new CustomFormBlock;
        $form_block->form_block_type = $form_block_type;
        $form_block->form_block_target_table_id = $form_block_target_table_id;

        return static::make($form_block, CustomTable::getEloquent($form_block_target_table_id));
    }


    /**
     * Get the value of custom_form_block
     *
     * @return  CustomFormBlock
     */ 
    public function getCustomFormBlock()
    {
        return $this->custom_form_block;
    }


    /**
     * Get the value of custom_form_block type
     *
     * @return  string
     */ 
    public function getCustomFormBlockType()
    {
        return array_get($this->custom_form_block, 'form_block_type');
    }


    /**
     * Get based table
     *
     * @return  CustomTable
     */ 
    public function getCustomTable()
    {
        return $this->custom_table;
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
            'label' => static::getBlockLabelHeader($this->target_table),
            'header_name' => $this->getHtmlHeaderName(),
            'suggests' => $this->getSuggestItems(),
            'custom_form_rows' => $this->getCustomFormRows(),
        ];
    }


    /**
     * Get suggest items
     *
     * @return Collection
     */
    public function getSuggestItems()
    {
        $suggests = collect();

        // get columns by form_block_target_table_id.
        $custom_columns = $this->target_table->custom_columns_cache;
        $custom_form_columns = [];
        
        // get array items
        $form_column_type = FormColumnType::COLUMN;
        // loop each column
        foreach ($custom_columns as $custom_column) {
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

            $column_item = FormColumn\Column::makeBySuggest($custom_column)->isSelected($has_custom_forms);
            $custom_form_columns[] = $column_item->getItemsForDisplay();
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
            $column_item = FormColumn\OtherBase::makeBySuggest($id)->isSelected(false);
            $custom_form_columns[] = $column_item->getItemsForDisplay();
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
        return collect($custom_form_columns)->map(function ($req_custom_form_column, $id) {
            $custom_form_column = new CustomFormColumn($req_custom_form_column);
            $custom_form_column->id = $id;
            return $custom_form_column;
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
    

    /**
     * Set formColumn list
     *
     * @param  Collection  $custom_form_column_items    FormColumn list
     *
     * @return  self
     */ 
    public function setCustomFormColumnItems(Collection $custom_form_column_items)
    {
        $this->custom_form_column_items = $custom_form_column_items;

        return $this;
    }
    

    /**
     * get Custom Form Boxes using custom_form_column_items. Contains row_no, column_no, width.
     *
     * @return  array
     */ 
    public function getCustomFormRows()
    {
        // grouping row_no and column_no;
        $groupRows = $this->custom_form_column_items->groupBy(function($custom_form_column_item){
            $custom_form_column = $custom_form_column_item->getCustomFormColumn();
            return $custom_form_column->row_no;
        });

        $groupRowColumns = $groupRows->map(function($groupRow){
            $groupColumns = $groupRow->groupBy(function($group){
                $group = $group->getCustomFormColumn();
                return $group->column_no;
            });

            $columns = $groupColumns->map(function($column){
                return [
                    'column_no' => $column->first()->getCustomFormColumn()->column_no,
                    'width' => $column->first()->getCustomFormColumn()->width,
                    'gridWidth' => $column->first()->getCustomFormColumn()->width * 3,
                    'custom_form_columns' => $column->map(function($c){
                        return $c->getItemsForDisplay();
                    })->toArray(),
                ];
            });

            return [
                'row_no' => $groupRow->first()->getCustomFormColumn()->row_no,
                'columns' => $columns,
            ];
        });

        return $groupRowColumns;
    }


    abstract public static function getBlockLabelHeader(CustomTable $custom_table);

}
