<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;

class WorkflowAction extends ModelBase
{
    use Traits\DatabaseJsonTrait;

    protected $appends = ['work_targets', 'work_conditions', 'commentType', 'flowNextType', 'flowNextCount', 'rejectAction'];
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
        return $this->getOption('work_conditions', []);
    }
    public function setWorkConditionsAttribute($work_conditions)
    {
        if(is_nullorempty($work_conditions)){
            return $this;
        }
        
        $work_conditions = jsonToArray($work_conditions);

        $this->setOption('work_conditions', $work_conditions);
        
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
        return $this->getOption('commentType');
    }
    public function setCommentTypeAttribute($commentType){
        $this->setOption('commentType', $commentType);
        return $this;
    }

    public function getFlowNextTypeAttribute(){
        return $this->getOption('flowNextType');
    }
    public function setFlowNextTypeAttribute($flowNextType){
        $this->setOption('flowNextType', $flowNextType);
        return $this;
    }

    public function getFlowNextCountAttribute(){
        return $this->getOption('flowNextCount');
    }
    public function setFlowNextCountAttribute($flowNextCount){
        $this->setOption('flowNextCount', $flowNextCount);
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
