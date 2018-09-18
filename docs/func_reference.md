# Function reference
## Introduction
Exment is an open source system using PHP.  
Also, I use [Laravel](https://laravel.com/), [laravel-admin](http://laravel-admin.org/docs/#/) for the framework.  
Therefore, all of these functions and models can be used.  

However, in Exment, there are parts that require special notation different from ordinary Laravel Eloquent, mainly for realizing custom tables.  
Also, in order to develop more effectively, necessary function processing etc are defined.  
On this page, we will describe the functions you have defined yourself with Exment.  
(The layout is being adjusted)



## Function list

### File, folder, path

#### path_join
---
Combine file paths.

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| pass_array | string (variable length argument) | target file path array |

##### Return value
| Type | Description |
| ---- | ---- |
| string | merged file path |


#### getFullpath
---
Get the full path of the file for a specific file.

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| filename | string | file name |
| disk | string | Laravel file name disk name |

##### Return value
| Type | Description |
| ---- | ---- |
| string | File name of target file |


### String

#### make_password
---
Create a password.
* Character string to be created: abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789! $ #% _-

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| length | int | string (default: 16) |

##### Return value
| Type | Description |
| ---- | ---- |
| string | password string |


#### make_randomstr
---
Create a random character string.
* Character string to be created: abcdefghjkmnpqrstuvwxyz23456789

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| length | int | character string |

##### Return value
| Type | Description |
| ---- | ---- |
| string | random character string |



#### make_uuid
---
Create a UUID.
Example: "15682b80-97cf-11e8-b287-2b0751d38875"

##### Argument
None

##### Return value
| Type | Description |
| ---- | ---- |
| string | UUID |


#### short_uuid
---
Create shortened UUID of 20 characters. It is used to create each table name, column name and data unique key of the database.
Example: "39bde6af771372f65cad"

##### Argument
None

##### Return value
| Type | Description |
| ---- | ---- |
| string | abbreviated ID |


#### make_licensecode
---
Create a license code string of 5 * 5 character strings (hyphens separated).
例："ghkn7-7xwmm-6sedf-8dn37-9wwg9"

##### Argument
None

##### Return value
| Type | Description |
| ---- | ---- |
| string | 5 * 5 character string (hyphen separated) |


#### pascalize
---
Converts a string to a Pascal case.

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| string | string | string to be converted |

##### Return value
| Type | Description |
| ---- | ---- |
| string | Pascal case string |


### Laravel

#### getModelName
---
Get the full path string of the model of the custom table.  
We also define methods for relationships between tables and methods for obtaining authority information.  
* When acquiring the model of the custom table, be sure to use this function.  

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| obj | string, CustomTable | Custom table name or CustomTable instance |
| get_name_only | bool | Acquires only the full path character string and does not do other function definition etc. (Default value: false) |

##### Return value
| Type | Description |
| ---- | ---- |
| string | Full path string of Model of custom table |


#### getDBTableName
---
Gets the table name of the custom table database.  
* Custom table DB table is created using random character string.  
Therefore, please use this function when acquiring the database.  

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| obj | string, CustomTable, array | Custom table name, CustomTable instance, or CustomTable instance array |

##### Return value
| Type | Description |
| ---- | ---- |
| string | table name of custom table |


#### getColumnName
---
Gets column name of custom column.  
* This column name is used for "Searchable" field.  

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| obj | CustomColumn, array | CustomColumn instance or CustomColumn instance array |

##### Return value
| Type | Description |
| ---- | ---- |
| string | custom column name |


#### getColumnNameByTable
---
We also specify the table and get the column name of the custom column.  
* This column name is used for "Searchable" field.  

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| tableObj | string, CustomTable, array | table name, CustomTable instance, or CustomTable instance array |
| column_name | string | Column name entered on screen |

##### Return value
| Type | Description |
| ---- | ---- |
| string | custom column name |


#### getLabelColumn
---
When custom table data is used as a search result or choice heading, we get a custom column for that heading.  

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| tableObj | string, CustomTable, array | table name, CustomTable instance, or CustomTable instance array |
| column_name | string | Column name entered on screen |

##### Return value
| Type | Description |
| ---- | ---- |
| CustomColumn | custom column |


#### getRelationName
---
Gets the relation name of the related table.  

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| obj | CustomRelation | Instance of CustomRelation |

##### Return value
| Type | Description |
| ---- | ---- |
| string | relation name |


#### getRelationNamebyObjs
---
Specify the parent table and child table to obtain the relation name of the related table.  

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| parent | string, CustomTable, array | parent table name, CustomTable instance, or CustomTable instance array |
| child | string, CustomTable, array | child table name, CustomTable instance, or CustomTable instance array |

##### Return value
| Type | Description |
| ---- | ---- |
| string | relation name |


#### getAuthorityName
---
Gets the authority name.  

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| obj | Authority | Instance of Authority |
| related_type | stirng | permission type |

##### Return value
| Type | Description |
| ---- | ---- |
| string | authority name |



#### getValue
---
Gets the value of the custom table for the specified column.

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| custom_value | CustomValue | Instance of CustomValue |
| column | string, CustomColumn, array | Custom table name, CustomColumn instance, or CustomColumn instance array |
| isonly_label | bool | Whether to acquire only label values ​​to be displayed (Default value: false) |

##### Return value
| Type | Description |
| ---- | ---- |
| mixed | The value of the custom table for the specified column |


#### getValueUseTable
---
Gets the value of the custom table for the specified column.  
* Specify a table and an array of custom value value fields as arguments.  

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| custom_table | string, CustomTable, array | table name, CustomTable instance, or CustomTable instance array |
| value | array | value array of custom values ​​|
| column | string, CustomColumn, array | Custom table name, CustomColumn instance, or CustomColumn instance array |
| isonly_label | bool | Whether to acquire only label values ​​to be displayed (Default value: false) |

##### Return value
| Type | Description |
| ---- | ---- |
| mixed | The value of the custom table for the specified column |


#### getParentValue
---
Gets the parent value of custom data.

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| custom_value | CustomValue | Instance of CustomValue |
| isonly_label | bool | Whether to acquire only label values ​​to be displayed (Default value: false) |

##### Return value
| Type | Description |
| ---- | ---- |
| mixed | parent value of custom data |



#### getChildrenValues
---
Get child data list related to custom data.

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| custom_value | CustomValue | Instance of CustomValue |
| relation_table | string, CustomTable, array | Name of table to be retrieved, CustomTable instance, or CustomTable instance array |

##### Return value
| Type | Description |
| ---- | ---- |
| collect (CustomValue) | related to custom data |


#### getSearchEnabledColumns
---
Get searchable column list.

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| table_name | string | table name |

##### Return value
| Type | Description |
| ---- | ---- |
| array | Searchable CustomColumn list |


#### createTable
---
Create a custom table in the DB.

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| obj | string, CustomTable, array | table name, CustomTable instance, or CustomTable instance array |

##### Return value
None


#### alterColumn
---
Create a searchable column in the DB table.

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| table_name | string | table name |
| column_name | string | column name |

##### Return value
None


### laravel-admin

#### getOptions
---
Create a select alternative of laravel-admin.  
* If the number of choices exceeds 100, the result is limited to the selected item because it is a narrowed down form by ajax.

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| custom_table | string, CustomTable, array | table name, CustomTable instance, or CustomTable instance array |
| selected_value | string | Selected value (id) |

##### Return value
| Type | Description |
| ---- | ---- |
| array | When the number of items is more than 101: key and heading of selected value Otherwise: key headings and list of selected table |


#### getOptionAjaxUrl
---
Create a URL for ajax for creating option in laravel-admin.

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| table | string, CustomTable, array | table name, CustomTable instance, or CustomTable instance array |

##### Return value
| Type | Description |
| ---- | ---- |
| string | ajax URL for creating option |


#### createSelectOptions
---
Create a choice when the column type of the custom column is "select" or "select_valtext".

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| obj | string, array | String or array of choices |
| isValueText | bool | Whether value / heading format |

##### Return value
| Type | Description |
| ---- | ---- |
| array | Array of choices |


#### setSelectOptionItem
---
Create a choice element to use with the createSelectOptions function.  
* Add to the argument options.  

##### Argument
| Name | Type | Description |
| ---- | ---- | ---- |
| item | string | choice character string |
| options | array | Array of choices (by reference) |
| isValueText | bool | Whether value / heading format |

##### Return value
None
