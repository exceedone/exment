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
class DefaultBlock extends BlockBase
{
    public static function getBlockLabelHeader()
    {
        return exmtrans('custom_form.table_default_label');
    }


    /**
     * Get deafult block for create
     *
     * @return self
     */
    public static function getDefaultBlock(CustomTable $custom_table)
    {
        $block = new CustomFormBlock;
        $block->id = null;
        $block->form_block_type = FormBlockType::DEFAULT;
        $block->form_block_target_table_id = $custom_table->id;
        $block->label = static::getBlockLabelHeader() . $custom_table->table_view_name;
        $block->form_block_view_name = $block->label;
        $block->available = 1;
        $block->options = [];
        $block->custom_form_columns = [];

        return new self($block, $custom_table);
    }
}
