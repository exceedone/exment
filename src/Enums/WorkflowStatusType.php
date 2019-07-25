<?php

namespace Exceedone\Exment\Enums;

class WorkflowStatusType extends EnumBase
{
    use EnumOptionTrait;

    const START = "0";
    const FLOW = "1";
    const END = "99";

    protected static $options = [
        'start' => ['id' => 0, 'name' => 'start', 'count' => '1', 'editable' => true],
        'flow' => ['id' => 1, 'name' => 'flow', 'count' => '3', 'editable' => true],
        'end' => ['id' => 99, 'name' => 'end', 'count' => '1', 'editable' => false],
    ];

}
