<?php

namespace Exceedone\Exment\Enums;

class WorkflowStatusType extends EnumBase
{
    use EnumOptionTrait;

    const START = "0";
    const FLOW = "1";
    const END = "99";

    protected static $options = [
        'start' => ['id' => 0, 'name' => 'start', 'status_name_trans' => 'workflow.start', 'count' => '1', 'editable' => true, 'enabled_flg' => true],
        'flow' => ['id' => 1, 'name' => 'flow', 'status_name_trans' => 'workflow.no_setting', 'count' => '3', 'editable' => true, 'enabled_flg' => false],
        'end' => ['id' => 99, 'name' => 'end', 'status_name_trans' => 'workflow.end', 'count' => '1', 'editable' => false, 'enabled_flg' => true],
    ];

}
