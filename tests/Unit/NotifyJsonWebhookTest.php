<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\Notify\JsonWebhook;
use Exceedone\Exment\Services\Notify\NotifyRunner;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * A notification as JSON to any URL (ISS02912): what is sent (the columns chosen only), the
 * signature, the secret kept encrypted, the tries and the bell when it is not sent, the URLs
 * refused, and nothing while the feature is off.
 */
class NotifyJsonWebhookTest extends UnitTestBase
{
    protected const URL = 'https://hooks.example.com/exment/in';
    protected const SECRET = 'the-secret-of-the-receiver';

    protected function setUp(): void
    {
        parent::setUp();
        // the guard asks the DNS for the host: this test host is allowed by name
        config([
            'exment.notify_webhook_allow_hosts' => 'hooks.example.com',
            'exment.notify_webhook_retry_seconds' => [0, 0],
            'exment.notify_mode' => NotifyRunner::SYNC,
        ]);
        \DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        \DB::rollBack();
        config(['exment.notify_mode' => 'auto']);
        System::clearCache();
        parent::tearDown();
    }

    protected function useFeature(bool $on): void
    {
        Features::save([Features::WEBHOOK_NOTIFY => ['enabled' => $on]]);
        System::clearRequestSession();
    }

    public function testTheJsonAndTheSignature(): void
    {
        $this->useFeature(true);
        Http::fake(['hooks.example.com/*' => Http::response('', 204)]);

        [$notify, $record] = $this->saveRecord('webhook_json', static::URL);

        $sent = Http::recorded();
        $this->assertCount(1, $sent);
        /** @var HttpRequest $request */
        $request = $sent[0][0];
        $this->assertSame(static::URL, $request->url());
        $this->assertSame('application/json', $request->header('Content-Type')[0] ?? null);

        // the receiver checks the body with the secret
        $timestamp = $request->header(JsonWebhook::HEADER_TIMESTAMP)[0] ?? '';
        $this->assertNotSame('', $timestamp);
        $this->assertSame(
            'sha256=' . hash_hmac('sha256', $timestamp . '.' . $request->body(), static::SECRET),
            $request->header(JsonWebhook::HEADER_SIGNATURE)[0] ?? null
        );

        $json = json_decode($request->body(), true);
        $this->assertSame('exment.notify', $json['event']);
        $this->assertSame($request->header(JsonWebhook::HEADER_DELIVERY)[0] ?? null, $json['id']);
        $this->assertSame('create_update_data', $json['trigger']);
        $this->assertSame('created', $json['action']);
        $this->assertSame(intval($notify->id), $json['notify']['id']);
        $this->assertSame('custom_value_edit_all', $json['table']['name']);
        $this->assertSame(intval($record->id), $json['record']['id']);
        $this->assertSame(strval($record->getUrl()), $json['record']['url']);
        $this->assertSame('admin', $this->userCode($json['user']['id']));
        // the columns chosen, nothing else
        $this->assertSame(['text' => 'webhook_json'], $json['record']['values']);
        $this->assertSame(['text' => 'webhook_json'], $json['record']['texts']);

        // the secret is kept encrypted
        $stored = Notify::findOrFail(intval($notify->id))->action_settings[0]['webhook_secret'];
        $this->assertStringStartsWith(JsonWebhook::SECRET_PREFIX, $stored);
        $this->assertStringNotContainsString(static::SECRET, $stored);
        $this->assertSame(static::SECRET, JsonWebhook::plainSecret($stored));
        // saved again: not encrypted twice
        $notify = Notify::findOrFail(intval($notify->id));
        $notify->active_flg = 1;
        $notify->save();
        $this->assertSame(static::SECRET, JsonWebhook::plainSecret(Notify::findOrFail(intval($notify->id))->action_settings[0]['webhook_secret']));
    }

    public function testTriedAgainAndToldWhenNotSent(): void
    {
        $this->useFeature(true);
        Http::fake(['hooks.example.com/*' => Http::sequence()->push('', 500)->push('', 503)->push('', 502)]);

        [$notify] = $this->saveRecord('webhook_fail', static::URL);

        $this->assertCount(3, Http::recorded(), 'tried 3 times');
        $bells = NotifyNavbar::withoutGlobalScopes()->where('notify_id', $notify->id)->get();
        $this->assertCount(1, $bells, 'the user who made the notification is told');
        $this->assertSame('admin', $this->userCode($bells[0]->target_user_id));
        $this->assertStringContainsString('hooks.example.com', strval($bells[0]->notify_body));
        $this->assertStringNotContainsString('/exment/in', strval($bells[0]->notify_body), 'only the host');
    }

    public function testNotTriedAgainForAClientError(): void
    {
        $this->useFeature(true);
        Http::fake(['hooks.example.com/*' => Http::sequence()->push('', 404)->push('', 200)]);

        [$notify] = $this->saveRecord('webhook_404', static::URL);

        $this->assertCount(1, Http::recorded());
        $this->assertSame(1, NotifyNavbar::withoutGlobalScopes()->where('notify_id', $notify->id)->count());
    }

    public function testTheServerItselfIsRefused(): void
    {
        $this->useFeature(true);
        Http::fake();

        foreach (['http://127.0.0.1/hook', 'http://169.254.169.254/latest/meta-data', 'http://[::1]/hook', 'http://[fe80::1]/hook', 'http://[::ffff:127.0.0.1]/hook'] as $i => $url) {
            [$notify] = $this->saveRecord('webhook_refused_' . $i, $url, false);
            $this->assertSame(1, NotifyNavbar::withoutGlobalScopes()->where('notify_id', $notify->id)->count(), $url);
            // not by the next record
            $notify->active_flg = 0;
            $notify->save();
        }
        Http::assertNothingSent();
    }

    public function testNothingWhileTheFeatureIsOff(): void
    {
        $this->useFeature(false);
        Http::fake();

        $this->assertArrayNotHasKey(NotifyAction::WEBHOOK, NotifyAction::selectOptions());
        $this->saveRecord('webhook_off', static::URL);
        Http::assertNothingSent();

        $this->useFeature(true);
        $this->assertArrayHasKey(NotifyAction::WEBHOOK, NotifyAction::selectOptions());
    }

    /**
     * A notification with a JSON webhook on custom_value_edit_all (admin makes it), then a record
     * saved by admin.
     *
     * @return array{0: Notify, 1: CustomValue}
     */
    protected function saveRecord(string $name, string $url, bool $withColumn = true): array
    {
        $table = CustomTable::getEloquent('custom_value_edit_all');
        $column = CustomColumn::getEloquent('text', $table);
        $this->assertNotNull($column);

        $userModel = getModelName(SystemTableName::USER);
        $admin = $userModel::withoutGlobalScopes()->where('value->user_code', 'admin')->first();
        \Auth::guard('admin')->login(LoginUser::where('base_user_id', $admin->id)->first());

        $notify = new Notify();
        $notify->notify_view_name = $name;
        $notify->target_id = $table->id;
        $notify->notify_trigger = NotifyTrigger::CREATE_UPDATE_DATA;
        $notify->mail_template_id = 6;
        $notify->active_flg = 1;
        $notify->trigger_settings = ['notify_saved_trigger' => ['created']];
        $notify->action_settings = [[
            'notify_action' => NotifyAction::WEBHOOK,
            'webhook_url' => $url,
            'webhook_columns' => $withColumn ? [strval($column->id)] : [],
            'webhook_secret' => static::SECRET,
        ]];
        $notify->save();
        System::clearCache();

        $record = $table->getValueModel()->setValue('text', $name);
        $record->save();

        return [$notify, $record];
    }

    /**
     * @param mixed $id
     */
    protected function userCode($id): string
    {
        $user = getModelName(SystemTableName::USER)::withoutGlobalScopes()->find($id);
        return $user ? strval($user->getValue('user_code')) : strval($id);
    }
}
