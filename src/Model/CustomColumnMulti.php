<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\ColumnItems;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\CalcFormulaType;
use Exceedone\Exment\Enums\ViewColumnType;
use Illuminate\Support\Facades\DB;

/**
 * Custom column multiple settings
 */
class CustomColumnMulti extends ModelBase // implements Interfaces\TemplateImporterInterface
{
    use Traits\UseRequestSessionTrait;
    use Traits\DatabaseJsonTrait;
    //use Traits\TemplateTrait;

    protected $appends = ['unique1', 'unique2', 'unique3'];
    protected $casts = ['options' => 'json'];
    protected $guarded = ['id'];
    protected $table = 'custom_column_multisettings';

    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    // public static $templateItems = [
    //     'excepts' => ['suuid'],
    //     'uniqueKeys' => [
    //         'export' => [
    //             'custom_table.table_name', 'column_name'
    //         ],
    //         'import' => [
    //             'custom_table_id', 'column_name'
    //         ],
    //     ],
    //     'langs' => [
    //         'keys' => ['column_name'],
    //         'values' => ['column_view_name', 'description', 'options.help', 'options.placeholder', 'options.select_item_valtext'],
    //     ],
    //     'parent' => 'custom_table_id',
    //     'uniqueKeyReplaces' => [
    //         [
    //             'replaceNames' => [
    //                 [
    //                     'replacingName' => 'options.select_target_table',
    //                     'replacedName' => [
    //                         'table_name' => 'options.select_target_table_name',
    //                     ],
    //                 ]
    //             ],
    //             'uniqueKeyClassName' => CustomTable::class,
    //         ],
    //     ]
    // ];

    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
    }
    public function setOption($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('options', $key, $val, $forgetIfNull);
    }
    
    public function getUnique1Attribute()
    {
        return $this->getOption('unique1_id');
    }
    public function setUnique1Attribute($unique1)
    {
        $this->setOption('unique1_id', $unique1);
        return $this;
    }

    public function getUnique2Attribute()
    {
        return $this->getOption('unique2_id');
    }
    public function setUnique2Attribute($unique2)
    {
        $this->setOption('unique2_id', $unique2);
        return $this;
    }

    public function getUnique3Attribute()
    {
        return $this->getOption('unique3_id');
    }
    public function setUnique3Attribute($unique3)
    {
        $this->setOption('unique3_id', $unique3);
        return $this;
    }
}
