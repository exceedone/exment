<?php
namespace Exceedone\Exment\Services\Plugin;

use Encore\Admin\Widgets\Form;
use Illuminate\Http\Request;
use Encore\Admin\Layout\Content;

/**
 * Plugin CRUD(and List) for each page render. 
 */
class PluginCrudRender
{
    public function __construct($plugin, $options = [])
    {
        $this->plugin = $plugin;
    }
    
    protected $plugin;

    /**
     * Plugin crud class.
     *
     * @var PluginCrudBase
     */
    protected $pluginClud;


    /**
     * Index. for grid.
     *
     * @param Request $request
     * @return void
     */
    public function index(Request $request)
    {
        $content = new Content();
        
        $content->body($this->grid());

        return $content;
    }

    
    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $rows = $this->pluginClud->getList();
        $definitions = $this->pluginClud->getFieldDefinitions();

        $tables = collect();

        foreach($rows as $row){

        }
    }
    
}
