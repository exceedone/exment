<?php
namespace Exceedone\Exment\Services\Plugin;

use Encore\Admin\Widgets\Form;

/**
 * Plugin CRUD(and List)
 */
abstract class PluginCrudBase
{
    use PluginBase, PluginPageTrait;
    
    public function __construct($plugin, $options = [])
    {
        $this->plugin = $plugin;
        $this->pluginOptions = new PluginOption\PluginOptionBatch($options);
    }

    /**
     * Get fields definitions
     *
     * @return array
     */
    abstract public function getFieldDefinitions() : array;

    /**
     * Get data list
     *
     * @return array
     */
    abstract public function getList(array $options = []) : array;

    /**
     * read single data
     *
     * @return array
     */
    abstract public function getSingleData($primaryValue, array $options = []) : array;

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
    abstract public function postCreate(array $posts, array $options = []) : mixed;

    /**
     * edit posted value
     *
     * @return mixed
     */
    abstract public function putEdit($primaryValue, array $posts, array $options = []) : mixed;
    
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
    public function enableEditData($primaryValue, array $options = []) : bool
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
    public function enableDeleteData($primaryValue, array $options = []) : bool
    {
        return true;
    }
}
