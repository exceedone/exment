# How to Develop Plugin
## First
This section describes how to develop the Exment plugin.  
Please refer to [Plug-in](plugin.md) for details about plug-in function and management method.  


# Create Plugin (trigger)

### Created config.json
- Create the following config.json file.

~~~ json
{
    "plugin_name": "PluginDemoTrigger",
    "uuid": "fa7de170-992a-11e8-b568-0800200c9a66",
    "plugin_view_name": "Plugin Trigger",
    "description": "This is a test to upload the plugin.",
    "author": "(Your Name)",
    "version": "1.0.0",
    "plugin_type": "trigger"
}
~~~

- Please fill in the plugin_name with an alphanumeric character.
- uuid is a string of 32 characters plus a hyphen, a total of 36 characters. It is used to make the plugin unique.
Please make from the following URL etc.
https://www.famkruithof.net/uuid/uuidgen
- For row "plugin_type", please enter "trigger".

### PHP file creation
- Create a PHP file like the following. Please name "Plugin.php".

~~~ php
<?php
namespace App\Plugins\PluginDemoTrigger;

use Exceedone\Exment\Services\Plugin\PluginTriggerBase;
class Plugin extends PluginTriggerBase
{
    /**
     * Plugin Trigger
     */
    public function execute()
    {
        admin_toastr('Plugin calling');
        return true;
    }
}
~~~
- Namespace should be ** App\Plugins\(plugin name) **.
- When it matches the trigger condition registered on the plugin management screen, the plugin is called and the execute function in Plugin.php is executed.

- The Plugin class extends class "PluginTriggerBase".  
PluginTriggerBase owns properties such as the caller's custom table $custom_table and table value $custom_value,  
When the execute function is called, its value is assigned to that property.  
For details on properties, please refer to [Plugin Reference](plugin_reference.md).

### Compressed to zip
Compress the above two files to zip with minimum configuration.  
The zip file name should be "(plugin_name) .zip".  
- PluginDemoTrigger.zip
     - config.json
     - Plugin.php
     - (Other necessary PHP files, image files etc)

### Sample Plugin
Now Preparing...

## Create plug-in (page)

### Created config.json
- Create the following config.json file.

~~~ json

{
    "name": "PluginDemoPage",
    "explain": "This is a test to upload the plugin.",
    "author":  "(Your Name)",
    "version": "1.0.0",
    "type": "page",
    "controller" : "PluginManagementController",
    "route": [
        {
            "uri": "",
            "method": [
                "get"
            ],
            "function": "index"
        },
        {
            "uri": "post",
            "method": [
                "post"
            ],
            "function": "post"
        },
        {
            "uri": "show_details/{id}",
            "method": [
                "get"
            ],
            "function": "show_details"
        },
        {
            "uri": "{id}/edit_test",
            "method": [
                "get"
            ],
            "function": "edit_test"
        },
        {
            "uri": "create_new",
            "method": [
                ""
            ],
            "function": "create_new"
        },
        {
            "uri": "{id}/update_test",
            "method": [
                "put"
            ],
            "function": "update_test"
        }
    ]
}

~~~
- Please fill in the plugin_name with an alphanumeric character.
- uuid is a string of 32 characters plus a hyphen, a total of 36 characters. It is used to make the plugin unique.  
Please make from the following URL etc.  
https://www.famkruithof.net/uuid/uuidgen
- For plugin_type, enter page.
- For controller, enter the class name of Contoller in the plugin to be executed.
- route defines the endpoint of the URL to be executed, its HTTP method, methods in Contoller in a list.  
    - uri: This is uri for page display. The actual URL is "http (s)://(URL of Exment)/admin/plugins/(URL set in the plugin administration screen)/(specified uri)".
    - method: HTTP method. Please fill in with get, post, put, delete.
    - function: Method in the Contoller to execute
    - Example: If the URL set on the plugin management screen is "test", the uri specified by config.json is "show_details / {id}" and the specified method is "get", "http(s)://(URL of Exment)/admin/plugins/test/show_details/{id}(method: GET)". An integer value is substituted for id.


### Creating Contoller
- Create a Contoller file like the following. The class name should be the name described in controller of config.json.

~~~ php
<?php

namespace App\Plugins\PluginDemoPage;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use App\Http\Controllers\Controller;
use Encore\Admin\Controllers\HasResourceActions;
use Exceedone\Exment\Model\PluginPage;
use Illuminate\Http\Request;

class PluginManagementController extends Controller
{
    use HasResourceActions;
    /**
     * Display a listing of the resource.
     *
     * @return Content|\Illuminate\Http\Response
     */

    public function index()
    {
        return Admin::content(function (Content $content) {

            $content->header('Plugin Page Management');
            $content->description('Plugin Page Management');

            $content->body($this->grid());
        });
    }

    public function show_details($id){

        return Admin::content(function (Content $content) use ($id) {

            $content->header('Show');
            $content->description('Show');

            $content->body($this->form()->edit($id));
        });
    }

    protected function grid()
    {
        return Admin::grid(PluginPage::class, function (Grid $grid) {

            $grid->column('plugin_name', 'Plugin Name')->sortable();
            $grid->column('plugin_author', 'Author')->sortable();

            $grid->disableExport();

        });
    }

    /**
     * Edit interface.
     *
     * @param $id
     * @return Content
     */
    public function edit_test($id)
    {
        return Admin::content(function (Content $content) use ($id) {

            $content->header('header');
            $content->description('description');

            $content->body($this->form($id)->edit($id));
        });
    }

    public function update_test($id){
        return $this->form()->update($id);
    }

    /**
     * Create interface.
     *
     * @return Content
     */
    public function create_new()
    {
        return Admin::content(function (Content $content) {

            $content->header('header');
            $content->description('description');

            $content->body($this->form());
        });
    }

    public function post(Request $request)
    {
        dd($request);
        return Admin::content(function (Content $content) {

            $content->header('header');
            $content->description('description');

            $content->body($this->form());
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        return Admin::form(PluginPage::class, function (Form $form) {

            $form->display('id', 'ID');
            $form->text('plugin_name', 'Plugin Name');
            $form->text('plugin_author', 'Author');
            $form->display('created_at', 'Created At');
            $form->display('updated_at', 'Updated At');
        });
    }

}

~~~
- Namespace should be ** App\Plugins\(plugin name) **.

- The public method name in Contoller is the name described in the function of config.json.

### Compressed to zip
Compress the above two files to zip with minimum configuration.  
The zip file name should be "(plugin_name) .zip".  
- PluginDemoPage.zip
     - config.json
     - PluginManagementController.php
     - (Other necessary PHP files, image files etc)

### Sample Plugin
Now Preparing...
