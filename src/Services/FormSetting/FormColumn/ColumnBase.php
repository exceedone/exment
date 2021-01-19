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

/**
 */
abstract class ColumnBase
{
    /**
     * @var CustomFormColumn
     */
    protected $custom_form_column;


    public function __construct(CustomFormColumn $custom_form_column)
    {
        $this->custom_form_column = $custom_form_column;
    }

    public static function make(CustomFormColumn $custom_form_column) : ColumnBase
    {
        switch(array_get($custom_form_column, 'form_column_type', FormColumnType::COLUMN))
        {
            case FormColumnType::COLUMN:
                return new Column($custom_form_column);
                
            case FormColumnType::OTHER:
                return new OtherBase($custom_form_column);
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
            'form_column_type' => $this->custom_form_column->form_column_type ?? FormColumnType::COLUMN,
            'column_no' => $this->custom_form_column->column_no ?? 1,
            'form_column_target_id' => $this->custom_form_column->form_column_target_id ?? null,
            
            'required' => $this->isRequired(),
            'column_view_name' => $this->getColumnViewName(),
            'header_column_name' => $this->getHtmlHeaderName(),
            'toggle_key_name' => make_uuid(),
        ];
    }


    /**
     * Get html header name
     *
     * @return void
     */
    protected function getHtmlHeaderName()
    {
        // add header name
        return '[custom_form_columns]['
            .(isset($this->custom_form_column['id']) ? $this->custom_form_column['id'] : 'NEW__'.make_uuid())
            .']';
    }
    

    /**
     * Get column's view name
     *
     * @return string|null
     */
    abstract public function getColumnViewName() : ?string;

    
    /**
     * Whether this column is required
     *
     * @return boolean
     */
    abstract public function isRequired() : bool;
}
