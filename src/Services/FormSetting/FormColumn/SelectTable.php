<?php
namespace Exceedone\Exment\Services\FormSetting\FormColumn;

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
class SelectTable extends Column
{
    /**
     * Get select table's columns.
     * Default is empty collection, If SelectTable, call this function
     *
     * @return Collection
     */
    public function getSelectTableColumns() : Collection
    {
        $result = [];
        // if not have array_get($custom_column, 'options.select_target_table'), conitnue
        if (!isset($this->custom_column)) {
            return $result;
        }

        $target_table = $this->custom_column->select_target_table;
        if (!isset($target_table)) {
            return $result;
        }

        // get custom table
        $custom_table = $this->custom_column->custom_table_cache;
        $custom_table->getSelectTableColumns(null, true)->each(function($select_column) use(&$result, $custom_table){
            // todo: get parent name
            // set table name if not $form_block_target_table_id and custom_table_eloquent's id
            // if (!isMatchString($custom_table->id, $form_block_target_table_id)) {
            //     $select_table_column_name = sprintf('%s:%s', $custom_table->table_view_name, array_get($this->custom_column, 'column_view_name'));
            //} else {
                $select_table_column_name = array_get($this->custom_column, 'column_view_name');
            //}
            // get select_table, user, organization columns
            $result[array_get($this->custom_column, 'id')] = $select_table_column_name;
        });

        return collect($result);
    }
}
