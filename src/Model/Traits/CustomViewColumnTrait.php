<?php

namespace Exceedone\Exment\Model\Traits;

use Encore\Admin\Facades\Admin;
use Carbon\Carbon;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ViewColumnType;

trait CustomViewColumnTrait
{
    /**
     * get ViewColumnTarget.
     * * we have to convert string if view_column_type is system for custom view form-display*
     */
    public function getViewColumnTargetAttribute(){
        if(!isset($this->view_column_type) || !isset($this->view_column_target_id)){
            return null;
        }
        if($this->view_column_type == ViewColumnType::SYSTEM){
            // get VIEW_COLUMN_SYSTEM_OPTIONS and get name.
            return collect(ViewColumnType::SYSTEM_OPTIONS())->first(function ($value) {
                return array_get($value, 'id') == $this->view_column_target_id;
            })['name'] ?? null;
        }
        elseif($this->view_column_type === ViewColumnType::PARENT_ID){
            return ViewColumnType::PARENT_ID;
        }
        else{
            return $this->view_column_target_id;
        }
    }
    
    /**
     * set ViewColumnTarget.
     * * we have to convert int if view_column_type is system for custom view form-display*
     */
    public function setViewColumnTargetAttribute($view_column_target){
        if (!is_numeric($view_column_target)) {
            if ($view_column_target === 'parent_id') {
                $this->view_column_type = ViewColumnType::PARENT_ID;
                $this->view_column_target_id = null;
            } else {
                $this->view_column_type = ViewColumnType::SYSTEM;
                $this->view_column_target_id = collect(ViewColumnType::SYSTEM_OPTIONS())->first(function ($value) use ($view_column_target) {
                    return array_get($value, 'name') == $view_column_target;
                })['id'] ?? null;
            }
        } else {
            $this->view_column_type = ViewColumnType::COLUMN;
            $this->view_column_target_id = $view_column_target;
        }
    }
}
