<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Auth\Permission as Checker;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Validator\EmailMultiline;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyBeforeAfter;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Services\Installer\InitializeFormTrait;
use Exceedone\Exment\Services\NotifyService;
use Illuminate\Http\Request;

class NotifyController extends AdminControllerBase
{
    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("notify.header"), exmtrans("notify.header"), exmtrans("notify.description"), 'fa-bell');
    }

    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);
        $content->withWarning(exmtrans("notify.notify_moved"), exmtrans("notify.message.notify_moved") . \Exment::getMoreTag('notify', 'notify.notify_moved_tag'));
        return $content;
    }


    public function getNotifyTriggerTemplate(Request $request)
    {
        $keyName = 'mail_template_id';
        $value = $request->input('value');

        // get mail key enum
        $enum = NotifyTrigger::getEnum($value);
        if (!isset($enum)) {
            return [$keyName => null];
        }

        // get mailKeyName
        $mailKeyName = $enum->getDefaultMailKeyName();
        if (!isset($mailKeyName)) {
            return [$keyName => null];
        }

        // get mail template
        $mail_template = CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE)
            ->getValueModel()
            ->where('value->mail_key_name', $mailKeyName)
            ->first();
    
        if (!isset($mail_template)) {
            return [$keyName => null];
        }

        return [
            $keyName => $mail_template->id
        ];
    }
}
