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
    abstract public function putEdit(Request $request, $primaryValue, array $posts, array $options = []) : mixed;
}
