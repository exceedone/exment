<?php
namespace Exceedone\Exment\Services\Plugin;

use Encore\Admin\Widgets\Form;
use Illuminate\Http\Request;

/**
 * Plugin CRUD(and List)
 */
abstract class PluginCrudBase extends PluginPublicBase
{
    use PluginBase, PluginPageTrait;
    
    public function __construct($plugin, $options = [])
    {
        $this->plugin = $plugin;
        $this->pluginOptions = new PluginOption\PluginOptionBatch($options);
        $this->setConnection();
    }
    
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
     * Set external connection
     *
     * @return array
     */
    abstract public function setConnection();

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
    abstract public function postCreate(array $posts, array $options = []);

    /**
     * edit posted value
     *
     * @return mixed
     */
<<<<<<< HEAD
    abstract public function putEdit(Request $request, $primaryValue, array $posts, array $options = []);
=======
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
>>>>>>> f8b991839309c5a5a626aec9607353fbf30a1b4d
}
