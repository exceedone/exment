<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Enums;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Tests\TestDefine;

class FNotifyTest extends ExmentKitTestCase
{
    /**
     * pre-excecute process before test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->login();
    }

    /**
     * test notify button html 
     */
    public function testNotifyButtonHtml()
    {
        // get value
        $custom_table = Model\CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $custom_value = $custom_table->getValueModel()->first();

        // get notify info
        $notify = $this->getNotify($custom_table, '_notify_button_single');
        $url = $this->getNotifyUrl($custom_table, $custom_value, $notify);

        // check config update
        $domDocument = $this->getDomDocument($url);

        $result = $this->hasContainsHtml($domDocument, 'form', function($attribute) use($custom_table, $custom_value){
            return $attribute->name == 'action' && $attribute->value == admin_urls('data', $custom_table->table_name, $custom_value->id, 'sendMail');
        });

        $this->assertTrue($result, 'Not has notify form');
    }
    
    
    /**
     * test notify button test html, and attachment
     */
    public function testNotifyButtonHtmlAttachment()
    {
        // get value
        $custom_table = Model\CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $file = Model\File::whereNotNull('parent_id')->whereNotNull('parent_type')
            ->where('parent_type', $custom_table->table_name)
            ->first();
        $custom_table = Model\CustomTable::getEloquent($file->parent_type);
        $custom_value = $custom_table->getValueModel($file->parent_id);

        // get notify info
        $notify = $this->getNotify($custom_table, '_notify_button_email');
        $url = $this->getNotifyUrl($custom_table, $custom_value, $notify);

        // check config update
        $domDocument = $this->getDomDocument($url);

        $result = $this->hasContainsHtml($domDocument, 'select', function($attribute){
            return $attribute->name == 'name' && $attribute->value == "mail_attachment[]";
        });

        $this->assertTrue($result, 'Not has notify form, select select items');;
    }


    /**
     * test notify button test post
     */
    public function testNotifyButtonPost()
    {
        \Notification::fake();
        \Notification::assertNothingSent();

        // get value
        $custom_table = Model\CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $custom_value = $custom_table->getValueModel()->first();

        // get notify info
        $notify = $this->getNotify($custom_table, '_notify_button_single');
        $url = admin_urls('data', $custom_table->table_name, $custom_value->id, 'sendMail');

        $mail_template = Model\CustomTable::getEloquent(Enums\SystemTableName::MAIL_TEMPLATE)
            ->getValueModel()
            ->where('value->mail_key_name', 'test_template_1')
            ->first();

        // post notify
        $mail_title = $mail_template->getValue('mail_subject');
        $mail_message = $mail_template->getValue('mail_body');
        $mail_key_name = $mail_template->getValue('mail_key_name');
        $mail_template_id = $notify->suuid;
        $target_user_keys = json_encode(['foobar@test.com']);

        // check config update
        $response = $this->post($url, [
            'mail_title' => $mail_title,
            'mail_message' => $mail_message,
            'mail_key_name' => $mail_key_name,
            'mail_template_id' => $mail_template_id,
            'target_users' => $target_user_keys,
        ]);

        $content = $response->response->getContent();
        if(is_json($content)){
            $json = json_decode($content, true);

            ///// Cannot test checking whether submitting mail.
            $this->assertTrue(array_get($json, 'result', false), 'Post submit error, message is : ' . json_encode($json['errors']));
        }
    }
    


    protected function getNotify(CustomTable $custom_table, string $suffix){
        // get notify info
        return Model\Notify::where('target_id', $custom_table->id)
            ->where('notify_trigger', Enums\NotifyTrigger::BUTTON)
            ->where('notify_view_name', $custom_table->table_name . $suffix)
            ->first()
            ;
    } 

    protected function getNotifyUrl(CustomTable $custom_table, CustomValue $custom_value, Notify $notify)
    {
        return admin_urls_query('data', $custom_table->table_name, $custom_value->id, 'notifyClick', [
            'targetid' => $notify->suuid,
            'widgetmodal_uuid' => short_uuid(),
        ]);
    } 


    protected function getDomDocument(string $url) : \DOMDocument
    {
        // check config update
        $response = $this->get($url);

        $content = $response->response->getContent();
        if(is_json($content)){
            $json = json_decode($content, true);
            if(array_get($json, 'result') === false){
                throw new \Exception(json_encode($json['errors']));
            }

            $html = array_get($json, 'body');
        }
        else{
            $html = $content;
        }
        
        $domDocument = new \DOMDocument();
        libxml_use_internal_errors( true );  
        $domDocument->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        return $domDocument;
    }

    
    protected function hasContainsHtml(\DOMDocument $domDocument, string $tagName, \Closure $callback) : bool
    {
        $selects = $domDocument->getElementsByTagName($tagName);
        if($selects->length == 0){
            return false;
        }

        foreach($selects as $select){
            $attributes = $select->attributes;
            foreach($attributes as $attribute){
                if($callback($attribute)){
                    return true;
                }
            }
        }

        return false;
    }
}
