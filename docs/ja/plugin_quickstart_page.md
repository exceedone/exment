## プラグイン(ページ)
Exmentに新しい画面を作成することができます。  
既存の機能とは全く異なるページを使用する場合にご利用ください。  

## 作成方法

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
