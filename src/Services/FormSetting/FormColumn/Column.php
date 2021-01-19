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
class Column extends ColumnBase
{
    /**
     * @var CustomColumn
     */
    protected $custom_column;


    public function __construct(CustomFormColumn $custom_form_column)
    {
        parent::__construct($custom_form_column);
        $this->custom_column = $custom_form_column->custom_column_cache;
        
        // get from form_column_target_id
        if (!isset($this->custom_column)) {
            $this->custom_column = CustomColumn::getEloquent(array_get($custom_form_column, 'form_column_target_id'));
        }
    }

    /**
     * Get column's view name
     *
     * @return string|null
     */
    public function getColumnViewName() : ?string
    {
        if (!isset($this->custom_column)) {
            return null;
        }
        return $this->custom_column->column_view_name;
    }

    
    /**
     * Whether this column is required
     *
     * @return boolean
     */
    public function isRequired() : bool
    {
        return boolval(array_get($this->custom_form_column, 'required')) || boolval(array_get($this->custom_column, 'required'));
    }


    /**
     * Get select table's columns.
     * Default is empty collection, If SelectTable, call this function
     *
     * @return Collection
     */
    public function getSelectTableColumns() : Collection{
        return collect();
    }
}
