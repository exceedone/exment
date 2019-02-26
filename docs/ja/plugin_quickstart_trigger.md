# プラグイン(トリガー)
Exmentの画面上で特定の操作を行った場合に実行され、値の更新などの処理を行うことができます。  
もしくは、一覧画面もしくはフォーム画面にボタンを追加し、クリック時に処理を行うことができます。  
特定の操作とは、以下の内容があります。  
- 保存直前：データの保存直前に、処理が起動します。  
- 保存後：データの保存後に、処理が起動します。  
- 一覧画面のメニューボタン：データ一覧画面の上部にボタンを追加し、クリック時にイベントを発生させます。  
- フォームのメニューボタン（新規作成時）：データの新規作成時の上部にボタンを追加し、クリック時にイベントを発生させます。  
- フォームのメニューボタン（更新時）：データの更新時の上部にボタンを追加し、クリック時にイベントを発生させます。  

## 作成方法

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
- namespaceは、**App\Plugins\(プラグイン名)**としてください。

- プラグイン管理画面で登録した、トリガーの条件に合致した場合に、プラグインが呼び出され、Plugin.php内のexecute関数が実行されます。  

- Pluginクラスは、クラスPluginTriggerBaseを継承しています。  
PluginTriggerBaseは、呼び出し元のカスタムテーブル$custom_table、テーブル値$custom_valueなどのプロパティを所有しており、  
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
