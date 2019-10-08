<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Encore\Admin\Form;

class WorkflowAction extends ModelBase
{
    use Traits\DatabaseJsonTrait;

    protected $appends = ['work_targets', 'work_conditions', 'comment_type', 'flow_next_type', 'flow_next_count', 'rejectAction'];
    protected $casts = ['options' => 'json'];

    protected $work_targets;

    public function workflow()
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    public function workflow_authorities()
    {
        return $this->hasMany(WorkflowAuthority::class, 'workflow_action_id')
            ->with(['user_organization']);
    }

    public function getWorkTargetsAttribute()
    {
        return WorkflowAuthority::where('workflow_action_id', $this->id)->get();
    }
    public function setWorkTargetsAttribute($work_targets)
    {
        if(is_nullorempty($work_targets)){
            return;
        }
        
        $this->work_targets = jsonToArray($work_targets);
        
        return $this;
    }

    public function getWorkConditionsAttribute()
    {
        $work_conditions = $this->getOption('work_conditions', []);

        foreach(range(0, 2) as $index){
            $work_condition_filters = array_get($work_conditions, "work_condition_filter_$index");
            if(!isset($work_condition_filters)){
                continue;
            }
            $work_conditions["work_condition_filter_$index"] = collect($work_condition_filters)->map(function($work_condition_filter){
                return new WorkflowActionCondition($work_condition_filter);
            })->toArray();
        }

        return $work_conditions;
    }
    public function setWorkConditionsAttribute($work_conditions)
    {
        if(is_nullorempty($work_conditions)){
            return $this;
        }
        
        $work_conditions = jsonToArray($work_conditions);

        // modify work_condition_filter
        $new_work_conditions = [];
        foreach($work_conditions as $key => $work_condition){

            if(strpos($key, 'work_condition_filter') === false){
                $new_work_conditions[$key] = $work_condition;
                continue;
            }

            // preg_match using key
            preg_match('/(?<filter>work_condition_filter_[0-9])+\[(?<index>.+)\]\[(?<name>.+)\]/u', $key, $match);

            if(is_nullorempty($match)){
                $new_work_conditions[$key] = $work_condition;
                continue;
            }

            $new_work_conditions[array_get($match, 'filter')][array_get($match, 'index')][array_get($match, 'name')] = $work_condition;
            //$new_work_conditions[$key]
        }

        // re-loop and replace work_condition_filter
        foreach($new_work_conditions as $key => &$new_work_condition){
            if(strpos($key, 'work_condition_filter') === false){
                continue;
            }

            $filters = [];
            foreach($new_work_condition as $k => &$n){
                // remove "_remove_" array
                if(array_has($n, Form::REMOVE_FLAG_NAME)){
                    if(boolval(array_get($n, Form::REMOVE_FLAG_NAME))){
                        array_forget($new_work_condition, $k);
                        break;
                    }
                    array_forget($n, Form::REMOVE_FLAG_NAME);
                }
                $filters[] = $n;
                array_forget($new_work_condition, $k);
            }

            // replace key name "_new_1" to index
            $new_work_conditions[$key] = $filters;
        }

        $this->setOption('work_conditions', $new_work_conditions);
        
        return $this;
    }

    public function getStatusFromNameAttribute(){
        if(is_numeric($this->status_from)){
            return WorkflowStatus::getEloquentDefault($this->status_from)->status_name;
        }
        elseif($this->status_from == Define::WORKFLOW_START_KEYNAME){
            return Workflow::getEloquentDefault($this->workflow_id)->start_status_name;
        }

        return null;
    }

    public function getCommentTypeAttribute(){
        return $this->getOption('comment_type');
    }
    public function setCommentTypeAttribute($comment_type){
        $this->setOption('comment_type', $comment_type);
        return $this;
    }

    public function getFlowNextTypeAttribute(){
        return $this->getOption('flow_next_type');
    }
    public function setFlowNextTypeAttribute($flow_next_type){
        $this->setOption('flow_next_type', $flow_next_type);
        return $this;
    }

    public function getFlowNextCountAttribute(){
        return $this->getOption('flow_next_count');
    }
    public function setFlowNextCountAttribute($flow_next_count){
        $this->setOption('flow_next_count', $flow_next_count);
        return $this;
    }

    public function getRejectActionAttribute(){
        return $this->getOption('rejectAction');
    }
    public function setRejectActionAttribute($rejectAction){
        $this->setOption('rejectAction', $rejectAction);
        return $this;
    }

    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
    }
    public function setOption($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('options', $key, $val, $forgetIfNull);
    }

    public function deletingChildren()
    {
        WorkflowAuthority::where('workflow_action_id', $this->id)->delete();
    }

    /**
     * set action authority
     */
    protected function setActionAuthority()
    {
        // target keys
        //TODO:workflow keyname
        $keys = [SystemTableName::USER, SystemTableName::ORGANIZATION, 'column', 'system'];
        foreach($keys as $key){
            $ids = array_get($this->work_targets, 'modal_' . $key, []);
            $values = collect($ids)->map(function($id) use($key){
                return [
                    'related_id' => $id,
                    'related_type' => $key,
                    'workflow_action_id' => $this->id
                ];
            })->toArray();

            \Schema::insertDelete(SystemTableName::WORKFLOW_AUTHORITY, $values, [
                'dbValueFilter' => function (&$model) use($key) {
                    $model->where('workflow_action_id', $this->id)
                        ->where('related_type', $key);
                },
                'dbDeleteFilter' => function (&$model, $dbValue) use($key) {
                    $model->where('workflow_action_id', $this->id)
                        ->where('related_id', array_get((array)$dbValue, 'related_id'))
                        ->where('related_type', $key);
                },
                'matchFilter' => function ($dbValue, $value) use($key) {
                    return array_get((array)$dbValue, 'workflow_action_id') == $value['workflow_action_id']
                        && array_get((array)$dbValue, 'related_id') == $value['related_id']
                        && array_get((array)$dbValue, 'related_type') == $value['related_type']
                        ;
                },
            ]);
        }
    }

    /**
     * Execute workflow action
     *
     * @param CustomValue $custom_value
     * @param array $data
     * @return void
     */
    public function executeAction($custom_value, $data = []){
        \DB::transaction(function() use($custom_value){
            $morph_type = $custom_value->custom_table_name;
            $morph_id = $custom_value->id;

            // update old WorkflowValue
            WorkflowValue::where([
                'morph_type' => $morph_type, 
                'morph_id' => $morph_id, 
                'latest_flg' => true
            ])->update(['latest_flg' => false]);

            $data = array_merge([
                'workflow_id' => array_get($this, 'workflow_id'),
                'morph_type' => $morph_type,
                'morph_id' => $morph_id,
                'workflow_status_id' => array_get($action, 'status_to') == Define::WORKFLOW_START_KEYNAME ? null :  array_get($action, 'status_to'),
                'latest_flg' => 1
            ], $data);
    
            WorkflowValue::create($data);
        });
    }

    /**
     * Get user, org, column, system user.
     *
     * @param orgAsUser if true, convert org as user
     * @return array user list
     */
    public function getWorkTargets($orgAsUser = false){

    }
    
    protected static function boot() {
        parent::boot();

        static::saved(function ($model) {
            $model->setActionAuthority();
        });
    }
}
