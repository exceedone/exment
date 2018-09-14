# プラグイン開発方法
## はじめに
ここでは、Exmentプラグインの開発方法について記載します。  
プラグインの機能・管理方法についての詳細は、[プラグイン](plugin.md)をご参照ください。  


## プラグイン(トリガー)作成

### config.json作成
- 以下のconfig.jsonファイルを作成します。  

~~~ json
{
    "plugin_name": "PluginDemoTrigger",
    "uuid": "fa7de170-992a-11e8-b568-0800200c9a66",
    "plugin_view_name": "Plugin Trigger",
    "description": "プラグインをアップロードするテストです。",
    "author": "(Your Name)",
    "version": "1.0.0",
    "plugin_type": "trigger"
}
~~~

- plugin_nameは、半角英数で記入してください。
- uuidは、32文字列+ハイフンの、合計36文字の文字列です。プラグインを一意にするために使用します。  
以下のURLなどから、作成を行ってください。  
https://www.famkruithof.net/uuid/uuidgen
- plugin_typeは、triggerと記入してください。  


### PHPファイル作成
- 以下のようなPHPファイルを作成します。名前は「Plugin.php」としてください。

~~~ php
<?php
namespace App\Plugins\PluginDemoTrigger;

use Exceedone\Exment\Plugin\PluginTrigger;
class Plugin extends PluginTrigger
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
- namespaceは、**App\Plugins\(プラグイン名)**としてください。

- プラグイン管理画面で登録した、トリガーの条件に合致した場合に、プラグインが呼び出され、Plugin.php内のexecute関数が実行されます。  

- Pluginクラスは、クラスPluginTriggerを継承しています。  
PluginTriggerは、呼び出し元のカスタムテーブル$custom_table、テーブル値$custom_valueなどのプロパティを所有しており、  
execute関数が呼び出された時点で、そのプロパティに値が代入されます。  
プロパティの詳細については、[プラグインリファレンス](plugin_reference.md)をご参照ください。  

### zipに圧縮
上記2ファイルを最小構成として、zipに圧縮します。  
zipファイル名は、「(plugin_name).zip」にしてください。  
- PluginDemoTrigger.zip
    - config.json
    - Plugin.php
    - (その他、必要なPHPファイル、画像ファイルなど)


### サンプルプラグイン
準備中...

## プラグイン(ページ)作成

### config.json作成
- 以下のconfig.jsonファイルを作成します。  

~~~ json

{
    "name": "PluginDemoPage",
    "explain": "プラグインをアップロードするテストです。",
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

- plugin_nameは、半角英数で記入してください。
- uuidは、32文字列+ハイフンの、合計36文字の文字列です。プラグインを一意にするために使用します。  
以下のURLなどから、作成を行ってください。  
https://www.famkruithof.net/uuid/uuidgen
- plugin_typeは、pageと記入してください。  
- controllerは、実行するプラグイン内の、Contollerのクラス名を記入してください。  
- routeは、実行するURLのエンドポイントと、そのHTTPメソッド、Contoller内のメソッドを一覧で定義します。
    - uri：ページ表示のためのuriです。実際のURLは、「http(s)://(ExmentのURL)/admin/plugins/(プラグイン管理画面で設定したURL)/(指定したuri)」になります。  
    - method：HTTPメソッドです。get,post,put,deleteで記入してください。
    - function：実行するContoller内のメソッド
    - 例：プラグイン管理画面で設定したURLを「test」、config.jsonで指定したuriが「show_details/{id}」、指定したmethodが「get」の場合、「http(s)://(ExmentのURL)/admin/plugins/test/show_details/{id}（メソッド：GET）」。idは整数値が代入される


### Contoller作成
- 以下のようなContollerファイルを作成します。クラス名は、config.jsonのcontrollerに記載の名称にしてください。

~~~ php
<?php

namespace App\Plugins\PluginDemoPage;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use App\Http\Controllers\Controller;
use Encore\Admin\Controllers\ModelForm;
use Exceedone\Exment\Model\PluginPage;
use Illuminate\Http\Request;

class PluginManagementController extends Controller
{
    use ModelForm;
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

            $grid->column('plugin_name', 'プラグイン名')->sortable();
            $grid->column('plugin_author', '作者')->sortable();

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
- namespaceは、**App\Plugins\(プラグイン名)**としてください。

- Contoller内のpublicメソッド名は、config.jsonのfunctionに記載の名称になります。

### zipに圧縮
上記2ファイルを最小構成として、zipに圧縮します。  
zipファイル名は、「(plugin_name).zip」にしてください。  
- PluginDemoPage.zip
    - config.json
    - PluginManagementController.php
    - (その他、必要なPHPファイル、画像ファイルなど)
