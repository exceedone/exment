<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Enums;
use Exceedone\Exment\Model;
use Exceedone\Exment\Tests\TestDefine;

class FNotifyTest extends ExmentKitTestCase
{
    /**
     * pre-excecute process before test.
     */
    protected function setUp()
    {
        parent::setUp();
        $this->login();
    }

    /**
     * test notify button test, and attachment
     */
    public function testNotifyButton()
    {
        // get value
        $custom_table = Model\CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $custom_value = $custom_table->getValueModel()->first();

        // get notify info
        $notify = Model\Notify::where('custom_table_id', $custom_table->id)->where('notify_trigger', Enums\NotifyTrigger::BUTTON)->first();
        
        $url = admin_urls_query('data', $custom_table->table_name, $custom_value->id, 'notifyClick', [
            'targetid' => $notify->suuid,
            'widgetmodal_uuid' => \short_uuid(),
        ]);

        // check config update
        $response = $this->get($url);
        $json = json_decode($response->response->getContent(), true);
        $html = array_get($json, 'body');

        $domDocument = new \DOMDocument();
        libxml_use_internal_errors( true );  
        $domDocument->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $forms = $domDocument->getElementsByTagName("form");
        if($forms->length == 0){
            $result = false;
        }else{
            $attributes = $forms[0]->attributes;
            foreach($attributes as $attribute){
                if($attribute->name == 'action' && $attribute->value == admin_urls('data', $custom_table->table_name, $custom_value->id, 'sendMail')){
                    $result = true;
                    break;
                }
            }
        }

        if(!isset($result) || !boolval($result)){
            $this->assertTrue(false, 'Not has notify form');
        }
        $this->assertTrue(true);
    }
    
    /**
     * test notify button test, and attachment
     */
    public function testNotifyButtonAttachment()
    {
        // get value
        $file = Model\File::whereNotNull('parent_id')->whereNotNull('parent_type')
            ->first();
        $custom_table = Model\CustomTable::getEloquent($file->parent_type);
        $custom_value = $custom_table->getValueModel($file->parent_id);

        // get notify info
        $notify = Model\Notify::where('custom_table_id', $custom_table->id)->where('notify_trigger', Enums\NotifyTrigger::BUTTON)->first();
        
        $url = admin_urls_query('data', $custom_table->table_name, $custom_value->id, 'notifyClick', [
            'targetid' => $notify->suuid,
            'widgetmodal_uuid' => \short_uuid(),
        ]);

        // check config update
        $response = $this->get($url);
        $json = json_decode($response->response->getContent(), true);
        $html = array_get($json, 'body');

        $domDocument = new \DOMDocument();
        libxml_use_internal_errors( true );  
        $domDocument->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $selects = $domDocument->getElementsByTagName("select");
        if($selects->length == 0){
            $result = false;
        }else{
            $attributes = $selects[0]->attributes;
            foreach($attributes as $attribute){
                if($attribute->name == 'action' && $attribute->value == admin_urls('data', $custom_table->table_name, $custom_value->id, 'sendMail')){
                    $result = true;
                    break;
                }
            }
        }

        if(!isset($result) || !boolval($result)){
            $this->assertTrue(false, 'Not has notify form, select select items');
        }
    }
}
