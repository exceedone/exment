<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Encore\Admin\Form;

class WorkflowActionCondition
{
    protected $view_column_target;
    protected $view_filter_condition;
    protected $view_filter_condition_value;

    public function __construct($value){
        $this->view_column_target = array_get($value, 'view_column_target');
        $this->view_filter_condition = array_get($value, 'view_filter_condition');
        $this->view_filter_condition_value = array_get($value, 'view_filter_condition_value');
    }
}
