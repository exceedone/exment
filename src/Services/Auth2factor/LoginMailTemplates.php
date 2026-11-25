<?php

namespace Exceedone\Exment\Services\Auth2factor;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\SystemTableName;

/**
 * The mail templates of the 2-step verification with where the login came from (ISS00268). A new
 * site gets them from system_template/data. An installed site: a template still exactly as Exment
 * wrote it (in Japanese or in English) gets the new text; one somebody changed stays (the values
 * ${login_datetime}, ${login_ip}, ${login_location}, ${login_user_agent} can be added to it).
 */
class LoginMailTemplates
{
    /** The lines added after ${valid_period_datetime}, by language. */
    public const LINES = [
        'ja' => "[ログインの日時]\n\${login_datetime}\n[IP アドレス]\n\${login_ip}\n[場所]\n\${login_location}\n[ブラウザー]\n\${login_user_agent}\n",
        'en' => "[login date and time]\n\${login_datetime}\n[IP address]\n\${login_ip}\n[location]\n\${login_location}\n[browser]\n\${login_user_agent}\n",
    ];

    /** The texts Exment wrote before (7.2.5 and older): mail_key_name => language => body. */
    public const OLD = [
        'verify_2factor' => [
            'ja' => "ID/パスワードでログインを行った方に送信しています。\n\n[認証コード]\n\${verify_code}\n[有効期限]\n\${valid_period_datetime}\n\n※ログインを行った覚えが無いのにも関わらず、本メールを受信した場合は、アカウントが不正に利用された可能性があります。パスワードの変更をお勧めします。\n※有効期限が切れた場合は、お手数ですが再度ID/パスワードを入力し、ログインを行ってください。",
            'en' => "Sent to the person who logged in with ID/password.\n\n[authentication code]\n\${verify_code}\n[expiration date]\n\${valid_period_datetime}\n\n*If you do not remember logging in but received this email, your account may have been used illegally. We recommend that you change your password.\n*If the expiration date has expired, please enter your ID/password again to log in.",
        ],
        'verify_2factor_google' => [
            'ja' => "ID/パスワードでログインを行った方に送信しています。\nGoogle2段階認証を登録します。\n\n[登録URL]\n\${2factor_google_register_url}\n[有効期限]\n\${valid_period_datetime}\n\n※ログインを行った覚えが無いのにも関わらず、本メールを受信した場合は、アカウントが不正に利用された可能性があります。パスワードの変更をお勧めします。\n※有効期限が切れた場合は、お手数ですが再度ID/パスワードを入力し、ログインを行ってください。",
            'en' => "Sent to the person who logged in with ID/password.\nRegister Google 2-step verification.\n\n[Registration URL]\n\${2factor_google_register_url}\n[expiration date]\n\${valid_period_datetime}\n\n* If you do not remember logging in but received this email, your account may have been used illegally. We recommend that you change your password.\n* If the expiration date has expired, please enter your ID/password again to log in.",
        ],
        'verify_2factor_system' => [
            'ja' => "システム全体で2段階認証を開始するための、認証コードを送信します。\n\n[認証コード]\n\${verify_code}\n[有効期限]\n\${valid_period_datetime}\n\nシステム設定画面で入力を行ってください。",
            'en' => "Send an authentication code to start two-step authentication for the entire system.\n\n[authentication code]\n\${verify_code}\n[expiration date]\n\${valid_period_datetime}\n\nEnter the information on the system setting screen.",
        ],
    ];

    /** The new text of an old one: the lines after ${valid_period_datetime}. */
    public static function withLogin(string $body, string $locale): string
    {
        $mark = "\${valid_period_datetime}\n";
        $position = strpos($body, $mark);
        if ($position === false) {
            return $body;
        }
        $position += strlen($mark);
        return substr($body, 0, $position) . static::LINES[$locale] . substr($body, $position);
    }

    /**
     * Give the new text to the templates still as Exment wrote them.
     *
     * @return array<int, string> the mail_key_name of the templates changed
     */
    public static function upgrade(): array
    {
        $table = CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE);
        if (!isset($table)) {
            return [];
        }
        $changed = [];
        $templates = $table->getValueModel()->newQuery()->get();
        foreach (static::OLD as $key => $bodies) {
            $template = $templates->first(function ($template) use ($key) {
                return $template->getValue('mail_key_name') === $key;
            });
            if (!isset($template)) {
                continue;
            }
            $body = str_replace("\r\n", "\n", strval($template->getValue('mail_body')));
            foreach ($bodies as $locale => $old) {
                if ($body === $old) {
                    $template->setValue('mail_body', static::withLogin($old, $locale));
                    $template->save();
                    $changed[] = $key;
                    break;
                }
            }
        }
        return $changed;
    }
}
