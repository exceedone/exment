<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model;
use Exceedone\Exment\Enums\MailTemplateType;
use Exceedone\Exment\Enums\SystemTableName;

trait MailTemplateTrait
{
    /**
     * Get Body Joined Header and Footer. Not replace
     *
     * @return string
     */
    public function getJoinedBody(){

        ///// get body using header and footer
        $header = $this->getHeaderFooter(MailTemplateType::HEADER);
        $body = $this->getValue('mail_body');
        $footer = $this->getHeaderFooter(MailTemplateType::FOOTER);

        // total body
        $mail_bodies = [];
        if (isset($header)) {
            $mail_bodies[]  = $header;
        }
        $mail_bodies[] = $body;
        if (isset($footer)) {
            $mail_bodies[]  = $footer;
        }

        return implode("\n\n", $mail_bodies);
    }
    
    /**
     * get mail template type
     */
    protected function getHeaderFooter($mailTemplateType)
    {
        $mail_template = getModelName(SystemTableName::MAIL_TEMPLATE)
            ::where('value->mail_template_type', $mailTemplateType)->first();
        if (!isset($mail_template)) {
            return null;
        }
        return $mail_template->getValue('mail_body');
    }

}
