<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\GroupCondition;
use Exceedone\Exment\Services\MailSender;
use Carbon\Carbon;

class NotifyPage extends ModelBase
{
    protected static function boot()
    {
        // add global scope
        static::addGlobalScope('target_user', function ($builder) {
            return $builder->where('target_user_id', \Exment::user()->base_user_id);
        });

        // 井坂さん：ここにcreatedメソッドを追加する（CustomValue.phpとかを参考にしてください）
        // 作成日時降順に並べ替えて、100件を超過していた場合、100件になるようにデータを削除する
        // 「100件」という数値は、 config('exment.notify_page_max', 100)で設定する。
    }
}
