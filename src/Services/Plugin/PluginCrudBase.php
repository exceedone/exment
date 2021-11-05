<?php
namespace Exceedone\Exment\Services\Plugin;

use Encore\Admin\Widgets\Form;
use Encore\Admin\Widgets\Grid\Grid;
use Encore\Admin\Widgets\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Http\Controllers\Controller;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Services\Plugin\PluginCrud;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomFormPriority;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums\FormLabelType;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ShowGridType;
use Exceedone\Exment\Services\FormSetting;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plugin CRUD(and List)
 */
abstract class PluginCrudBase extends PluginPublicBase
{
    use PluginBase, PluginPageTrait;
    
    public function __construct($plugin, $options = [])
    {
        $this->plugin = $plugin;
        $this->pluginOptions = new PluginOption\PluginOptionCrud($options);
    }

    /**
     * content title
     *
     * @var string
     */
    protected $title;

    /**
     * content description
     *
     * @var string
     */
    protected $description;

    /**
     * content icon
     *
     * @var string
     */
    protected $icon;

    /**
     * Crud grid class name. If customize, change class name.
     *
     * @var string
     */
    protected $gridClass = PluginCrud\CrudGrid::class;

    
    public function _plugin()
    {
        return $this->plugin;
    }
    
    /**
     * Get route uri for page
     *
     * @return string
     */
    public function getRouteUri($endpoint = null)
    {
        if (!isset($this->plugin)) {
            return null;
        }

        return $this->plugin->getRouteUri($endpoint);
    }

    /**
     * Get fields definitions
     *
     * @return array|Collection
     */
    abstract public function getFieldDefinitions();

    /**
     * Get data list
     *
     * @return Collection
     */
    abstract public function getList(array $options = []) : Collection;

    /**
     * Get data paginate
     *
     * @return LengthAwarePaginator
     */
    abstract public function getPaginate(array $options = []) : LengthAwarePaginator;

    /**
     * read single data
     *
     * @return array|Collection
     */
    abstract public function getSingleData($primaryValue, array $options = []);

    /**
     * set form info
     *
     * @return Form
     */
    abstract public function setForm(Form $form, bool $isCreate, array $options = []) : Form;
    
    /**
     * post create value
     *
     * @return mixed
     */
    abstract public function postCreate(array $posts, array $options = []);

    /**
     * edit posted value
     *
     * @return mixed
     */
    abstract public function putEdit($primaryValue, array $posts, array $options = []);
    
    /**
     * delete value
     *
     * @return mixed
     */
    abstract public function delete($primaryValue, array $posts, array $options = []);


    /**
     * Whether create data. If false, disable create button.
     * Default: true
     *
     * @return bool
     */
    public function enableCreate(array $options = []) : bool
    {
        return true;
    }

    /**
     * Whether edit all data. If false, disable edit button and link.
     * Default: true
     *
     * @return bool
     */
    public function enableEdit(array $options = []) : bool
    {
        return true;
    }

    /**
     * Whether edit target data. If false, disable edit button and link.
     * Default: true
     *
     * @return bool
     */
    public function enableEditData($value, array $options = []) : bool
    {
        return true;
    }

    /**
     * Whether delete all data. If false, disable delete button and link.
     * Default: true
     *
     * @return bool
     */
    public function enableDelete(array $options = []) : bool
    {
        return true;
    }

    /**
     * Whether delete target data. If false, disable delete button and link.
     * Default: true
     *
     * @return bool
     */
    public function enableDeleteData($value, array $options = []) : bool
    {
        return true;
    }
    /**
     * Whether export data. If false, disable export button and link.
     * Default: false
     *
     * @return bool
     */
    public function enableExport(array $options = []) : bool
    {
        return false;
    }

    /**
     * Get content
     *
     * @return Content
     */
    public function getContent() : Content
    { 
        $content = new Content();
        if(!is_nullorempty($title = $this->plugin->getOption('title', $this->title))){
            $content->header($title);
        }
        if(!is_nullorempty($description = $this->plugin->getOption('description', $this->description))){
            $content->description($description);
        }
        if(!is_nullorempty($headericon = $this->plugin->getOption('icon', $this->icon))){
            $content->headericon($headericon);
        }

        return $content;
    }


    /**
     * Index. for grid.
     *
     * @param Request $request
     * @return void
     */
    public function index()
    {
        $className = $this->gridClass;
        return (new $className($this->plugin, $this))->index();
    }
}
