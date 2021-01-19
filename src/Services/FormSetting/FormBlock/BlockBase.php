<?php
namespace Exceedone\Exment\Services\FormSetting\FormBlock;

use App\Http\Controllers\Controller;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomFormPriority;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Linkage;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\SystemColumn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
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
        switch(array_get($custom_form_block, 'form_block_type', FormBlockType::DEFAULT)){
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
            'form_block_view_name' => $this->custom_form_block->label ?? null,
            'form_block_target_table_id' => $this->custom_form_block->form_block_target_table_id ?? $this->target_table->id ?? $this->custom_table->id ?? null,
            'label' => static::getBlockLabelHeader(),
            'header_name' => $this->getHtmlHeaderName(),
            'suggests' => $this->getSuggestItems(),
            'custom_form_columns' => [],
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
