# Plugin reference

## List of classes

### PluginBase
- namespace Exceedone\Exment
- Common base class for plugins (triggers) and plugins (pages).

##### Property
| Name | Type | Description |
| ---- | ---- | ---- |
| plugin | Plugin | Eloquent instance of the plugin |

### PluginTrigger
- namespace Exceedone\Exment
- extends Exceedone\Exment\PluginBase
- Base class for plugins (triggers).

##### Property
| Name | Type | Description |
| ---- | ---- | ---- |
| custom_table | CustomTable | Eloquent instance of the custom table to be plugged in |
| custom_value | CustomValue | Eloquent instance with custom value to be plugged in when displaying the form |
| Custom_form | CustomForm | Eloquent instance of custom form targeted for plugin invocation when displaying the form |
| isCreate | bool | When form is displayed, whether it is a newly created form |