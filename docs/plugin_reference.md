# プラグインリファレンス

## クラス一覧

### PluginBase
- namespace Exceedone\Exment
- プラグイン(トリガー)、プラグイン(ページ)の共通基底クラス。

##### プロパティ
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| plugin | Plugin | プラグインのEloquentインスタンス |

### PluginTrigger
- namespace Exceedone\Exment
- extends Exceedone\Exment\PluginBase
- プラグイン(トリガー)の基底クラス。

##### プロパティ
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| custom_table | CustomTable | プラグイン呼び出し対象の、カスタムテーブルのEloquentインスタンス |
| custom_value | CustomValue | フォーム表示時、プラグイン呼び出し対象の、カスタム値のEloquentインスタンス |
| custom_form | CustomForm | フォーム表示時、プラグイン呼び出し対象の、カスタムフォームのEloquentインスタンス |
| isCreate | bool | フォーム表示時、新規作成フォームかどうか |

