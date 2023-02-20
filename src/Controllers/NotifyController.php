<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\NotifyTrigger;
use Illuminate\Http\Request;

class NotifyController extends AdminControllerBase
{
    /**
     * @param Request $request
     * @phpstan-ignore-next-line
     */
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
