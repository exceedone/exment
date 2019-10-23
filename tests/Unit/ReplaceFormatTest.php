<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;
use Exceedone\Exment\Services\ReplaceFormat\ReplaceFormatService;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Enums\SystemColumn;

class ReplaceFormatTest extends TestCase
{
    public function testReplaceDateString()
    {
        $dateStrings = [
            'ymdh' => 'YmdH',
            'ymd' => 'Ymd',
            'ym' => 'Ym',

            'hi' => 'Hi',
        ];

        $now = \Carbon\Carbon::now();

        foreach($dateStrings as $key => $value){
            $text = ReplaceFormatService::replaceTextFromFormat('${' . $key . '}');
            $this->assertTrue($text == $now->format($value));
        }
    }

    public function testReplaceDateValue()
    {
        $dateValues = [
            'year' => 'year',
            'month' => 'month',
            'day' => 'day',
            'hour' => 'hour',
            //'minute' => 'minute',
            //'second' => 'second',
            'y' => 'year',
            'm' => 'month',
            'd' => 'day',
            'h' => 'hour',
            //'i' => 'minute',
            //'s' => 'second',
        ];


        $now = \Carbon\Carbon::now();

        foreach($dateValues as $key => $value){
            $text = ReplaceFormatService::replaceTextFromFormat('${' . $key . '}');
            $this->assertTrue($text == $now->{$value});
        }
    }

    public function testReplaceValueUrl()
    {
        $info = CustomTable::getEloquent('information')->getValueModel(1);
        $text = ReplaceFormatService::replaceTextFromFormat('${value_url}', $info);
        $this->assertTrue($info->getUrl() == $text);
    }

    public function testReplaceSystemValue()
    {
        $info = CustomTable::getEloquent('information')->getValueModel(1);
        
        $systemValues = collect(SystemColumn::getOptions())->pluck('name')->toArray();

        foreach($systemValues as $systemValue){
            $text = ReplaceFormatService::replaceTextFromFormat('${' . $systemValue . '}', $info);
            $this->assertTrue($info->{$systemValue} == $text);
        }
    }

    public function testReplaceValue()
    {
        $info = CustomTable::getEloquent('information')->getValueModel(1);
        
        $custom_columns = CustomTable::getEloquent('information')->custom_columns;

        foreach($custom_columns as $custom_column){
            $text = ReplaceFormatService::replaceTextFromFormat('${value:' . $custom_column->column_name . '}', $info);
            $this->assertTrue($info->getValue($custom_column->column_name, true) == $text);
        }
    }

    public function testReplaceUuid()
    {
        $keys = ['uuid'];
        foreach($keys as $key){
            $text = ReplaceFormatService::replaceTextFromFormat('${' . $key . '}');
            $this->assertTrue(!\is_nullorempty($text));
        }
    }

    public function testReplaceSystem()
    {
        $keys = [
            'site_name' => System::site_name(), 
            'site_name_short' => System::site_name_short(),
            'system_mail_from' => System::system_mail_from(),
            'system_url' => admin_url(),
            'login_url' => admin_url('auth/login'),
        ];
        foreach($keys as $key => $value){
            $text = ReplaceFormatService::replaceTextFromFormat('${system:' . $key . '}');
            $this->assertTrue($text == $value);
        }
    }
    
    public function testReplaceWorkflow()
    {
        // get workflow last value
        $workflow_value = WorkflowValue::orderBy('id', 'desc')->first();
        if(!isset($workflow_value)){
            return;
        }

        $workflow_action = $workflow_value->workflow_action;
        $workflow = Workflow::getEloquentDefault($workflow_value->workflow_id);
        
        $custom_value = CustomTable::getEloquent($workflow_value->morph_type)->getValueModel($workflow_value->morph_id);
        $statusTo = $workflow_action->getStatusToId($custom_value);

        $keys = [
            'action_user' => $workflow_value->created_user, 
            'action_name' => $workflow_action->action_name,
            'status_name' => WorkflowStatus::getWorkflowStatusName($statusTo, $workflow),
            'status_from_name' => WorkflowStatus::getWorkflowStatusName($workflow_action->status_from, $workflow),
            'comment' => $workflow_value->comment,
        ];
        foreach($keys as $key => $value){
            $text = ReplaceFormatService::replaceTextFromFormat('${workflow:' . $key . '}', $custom_value, [
                'workflow_action' => $workflow_action,
                'workflow_value' => $workflow_value,
            ]);
            $this->assertTrue($text == $value);
        }
    }
}
