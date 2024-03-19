<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model;
use Exceedone\Exment\Enums\MailTemplateType;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\File as ExmentFile;

/**
 * @used-by \Exceedone\Exment\Services\ClassBuilder
 */
trait MailTemplateTrait
{
    /**
     * Get Body Joined Header and Footer. Not replace
     *
     * @return string
     */
    public function getJoinedBody()
    {

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
        $mail_template = getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_template_type', $mailTemplateType)->first();
        if (!isset($mail_template)) {
            return null;
        }
        return $mail_template->getValue('mail_body');
    }

    /**
     * Whether this model disable delete
     *
     * @return boolean
     */
    public function disabled_delete_trait()
    {
        if (in_array($this->getValue('mail_key_name'), MailKeyName::arrays())) {
            return true;
        }

        $notify = Model\Notify::firstRecordCache(function ($notify) {
            return isMatchString(array_get($notify, 'mail_template_id'), $this->id);
        });

        return !is_nullorempty($notify);
    }

    public function getCustomAttachments($custom_value)
    {
        if ($custom_value instanceof Model\CustomValue) {
            $attachments = $this->getValue('custom_attachments');
            if (is_string($attachments)) {
                $str = str_replace(array("\r\n","\r","\n"), "\n", $attachments);
                if (!is_nullorempty($str) && mb_strlen($str) > 0) {
                    // loop for split new line
                    $attachments = explode("\n", $str);
                }
            }

            $files = collect();
            collect($attachments)->filter()->map(function ($attachment) use ($custom_value, &$files) {
                $files = $files->merge(replaceTextFromFormat($attachment, $custom_value, [
                    'getReplaceValue' => true
                ]));
            });

            return $files->filter()->map(function ($attachment) {
                return ExmentFile::getData($attachment);
            });
        }
        return null;
    }
}
