# 関数リファレンス
## はじめに
ExmentはPHPを使用したオープンソースシステムです。  
また、フレームワークに[Laravel](https://laravel.com/)、[laravel-admin](http://laravel-admin.org/docs/#/)を使用しています。  
そのため、これらの使用している関数やモデルはすべて、使用できます。  

ただしExmentでは、主にカスタムテーブルの実現のために、通常のLaravelのEloquentとは異なる、特殊な記法が必要な箇所があります。  
また、より有効に開発するために、必要な関数処理などを定義しています。  
このページでは、Exmentで独自に定義している関数を記載します。
(レイアウトは調整中です)



## 関数一覧

### ファイル・フォルダ・パス

#### path_join
---
ファイルパスを結合します。

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| pass_array | string(可変長引数) | 対象のファイルパス配列 |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| string | 結合したファイルパス |


#### getFullpath
---
特定のファイルを対象に、ファイルのフルパスを取得します。

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| filename | string | ファイル名 |
| disk | string | Laravelのファイル名のディスク名 |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| string | 対象のファイルのファイル名 |


### 文字列

#### make_password
---
パスワードを作成します。  
※作成対象となる文字列：abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!$#%_-

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| length | int | 文字列(既定値：16) |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| string | パスワード文字列 |


#### make_randomstr
---
ランダム文字列を作成します。  
※作成対象となる文字列：abcdefghjkmnpqrstuvwxyz23456789

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| length | int | 文字列 |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| string | ランダム文字列 |



#### make_uuid
---
UUIDを作成します。  
例： "15682b80-97cf-11e8-b287-2b0751d38875"

##### 引数
なし

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| string | UUID |


#### short_uuid
---
20文字の短縮UUIDを作成します。データベースの各テーブル名、カラム名、データの一意キー作成に使用しています。  
例："39bde6af771372f65cad"

##### 引数
なし

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| string | 短縮ID |


#### make_licensecode
---
5*5文字の文字列(ハイフン区切り)のライセンスコード系文字列を作成します。
例："ghkn7-7xwmm-6sedf-8dn37-9wwg9"

##### 引数
なし

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| string | 5*5文字の文字列(ハイフン区切り) |


#### pascalize
---
文字列をパスカルケースに変換します。

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| string | string | 変換対象の文字列 |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| string | パスカルケース文字列 |


### Laravel

#### getModelName
---
カスタムテーブルのModelのフルパス文字列を取得します。  
テーブル間のリレーションや権限情報取得のためのメソッドも、同時に定義します。  
※カスタムテーブルのModelを取得する場合、必ずこの関数を使用してください。  

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| obj | string,CustomTable | カスタムテーブル名、もしくはCustomTableインスタンス |
| get_name_only | bool | フルパス文字列のみ取得し、他の関数定義などは行いません。(既定値：false) |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| string | カスタムテーブルのModelのフルパス文字列 |


#### getDBTableName
---
カスタムテーブルのデータベースのテーブル名を取得します。  
※カスタムテーブルのDBテーブルは、ランダム文字列を使用して作成しています。  
そのため、データベースを取得するときは、この関数を使用してください。

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| obj | string,CustomTable,array | カスタムテーブル名、CustomTableインスタンス、もしくはCustomTableインスタンス配列 |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| string | カスタムテーブルのテーブル名 |


#### getIndexColumnName
---
カスタム列の列名を取得します。  
※この列名は、「検索可能」フィールドに使用します。  

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| obj | CustomColumn,array | CustomColumnインスタンス、もしくはCustomColumnインスタンス配列 |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| string | カスタム列名 |


#### getIndexColumnNameByTable
---
テーブルも指定して、カスタム列の列名を取得します。  
※この列名は、「検索可能」フィールドに使用します。  

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| tableObj | string,CustomTable,array | テーブル名、CustomTableインスタンス、もしくはCustomTableインスタンス配列 |
| column_name | string | 画面上で入力した列名 |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| string | カスタム列名 |


#### getLabelColumn
---
カスタムテーブルのデータを、検索結果や選択肢の見出しとして使用する場合に、その見出しのカスタム列を取得します。  

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| tableObj | string,CustomTable,array | テーブル名、CustomTableインスタンス、もしくはCustomTableインスタンス配列 |
| column_name | string | 画面上で入力した列名 |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| CustomColumn | カスタム列 |


#### getRelationName
---
関連テーブルのリレーション名を取得します。  

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| obj | CustomRelation | CustomRelationのインスタンス |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| string | リレーション名 |


#### getRelationNameByTables
---
親テーブル・子テーブルを指定して、関連テーブルのリレーション名を取得します。  

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| parent | string,CustomTable,array | 親テーブル名、CustomTableインスタンス、もしくはCustomTableインスタンス配列 |
| child | string,CustomTable,array | 子テーブル名、CustomTableインスタンス、もしくはCustomTableインスタンス配列 |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| string | リレーション名 |


#### getRoleName
---
権限名を取得します。  

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| obj | Role | Roleのインスタンス |
| related_type | stirng | 権限種類 |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| string | 権限名 |



#### getValue
---
指定した列のカスタムテーブルの値を取得します。  

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| custom_value | CustomValue | CustomValueのインスタンス |
| column | string,CustomColumn,array | カスタムテーブル名、CustomColumnインスタンス、もしくはCustomColumnインスタンス配列 |
| isonly_label | bool | 画面表示するラベル値のみ取得するかどうか(既定値:false) |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| mixed | 指定した列の、カスタムテーブルの値 |


#### getValueUseTable
---
指定した列のカスタムテーブルの値を取得します。  
※テーブルと、カスタム値のvalueフィールドの配列を引数に指定します。

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| custom_table | string,CustomTable,array | テーブル名、CustomTableインスタンス、もしくはCustomTableインスタンス配列 |
| value | array | カスタム値のvalue配列 |
| column | string,CustomColumn,array | カスタムテーブル名、CustomColumnインスタンス、もしくはCustomColumnインスタンス配列 |
| isonly_label | bool | 画面表示するラベル値のみ取得するかどうか(既定値:false) |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| mixed | 指定した列の、カスタムテーブルの値 |


#### getParentValue
---
カスタムデータの親となる値を取得します。  

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| custom_value | CustomValue | CustomValueのインスタンス |
| isonly_label | bool | 画面表示するラベル値のみ取得するかどうか(既定値:false) |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| mixed | カスタムデータの親となる値 |



#### getChildrenValues
---
カスタムデータに関連する、子データ一覧を取得します。  

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| custom_value | CustomValue | CustomValueのインスタンス |
| relation_table | string,CustomTable,array | 取得対象のテーブル名、CustomTableインスタンス、もしくはCustomTableインスタンス配列 |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| collect(CustomValue) | カスタムデータに関連する |


#### getSearchEnabledColumns
---
検索可能な列一覧を取得します。  

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| table_name | string | テーブル名 |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| array | 検索可能なCustomColumn一覧 |


#### createTable
---
DBにカスタムテーブルを作成します。  

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| obj | string,CustomTable,array | テーブル名、CustomTableインスタンス、もしくはCustomTableインスタンス配列 |

##### 戻り値
なし


#### alterColumn
---
DBのテーブルに、検索可能な列を作成します。  

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| table_name | string | テーブル名 |
| column_name | string | 列名 |

##### 戻り値
なし


### laravel-admin

#### getOptions
---
laravel-adminの、selectの選択肢を作成します。  
※選択肢が100件を超える場合、ajaxによる絞り込み形式となるため、結果は選択済の項目のみになります。  

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| custom_table | string,CustomTable,array | テーブル名、CustomTableインスタンス、もしくはCustomTableインスタンス配列 |
| selected_value | string | 選択済の値(id) |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| array | 件数が101件以上のとき：選択した値のキーと見出し それ以外のとき：選択したテーブルの、キーの見出しと一覧 |


#### getOptionAjaxUrl
---
laravel-adminの、option作成用のajaxのURLを作成します。  

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| table | string,CustomTable,array | テーブル名、CustomTableインスタンス、もしくはCustomTableインスタンス配列 |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| string | option作成用のajaxのURL |


#### createSelectOptions
---
カスタム列の列種類が"select"もしくは"select_valtext"のときの、選択肢を作成します。

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| obj | string,array | 選択肢の文字列もしくは配列 |
| isValueText | bool | 値・見出しの形式かどうか |

##### 戻り値
| 種類 | 説明 |
| ---- | ---- |
| array | 選択肢の配列 |


#### setSelectOptionItem
---
関数createSelectOptionsで使用する、選択肢の要素の作成します。  
※引数optionsに追加します。

##### 引数
| 名前 | 種類 | 説明 |
| ---- | ---- | ---- |
| item | string | 選択肢の文字列 |
| options | array | 選択肢の配列(参照渡し) |
| isValueText | bool | 値・見出しの形式かどうか |

##### 戻り値
なし
