<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Notifications\TeamsWorkflowSender;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\NotifyService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Notifications to a person's Teams chat through a Teams workflow (ISS02863): the JSON the workflow
 * gets (with the Adaptive Card), the address of the workflow kept out of the logs, and nothing sent
 * while the feature is not used or the address is not set.
 */
class TeamsPersonalNotifyTest extends UnitTestBase
{
    protected const URL = 'https://flow.example.com/workflows/secret-path/triggers/manual/paths/invoke?sig=TOPSECRET';

    protected function setUp(): void
    {
        parent::setUp();
        // the guard asks the DNS for the host: this test host is allowed by name
        config(['exment.notify_webhook_allow_hosts' => 'flow.example.com']);
        \DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        \DB::rollBack();
        System::clearCache();
        parent::tearDown();
    }

    protected function useFeature(bool $on): void
    {
        Features::save([Features::TEAMS_NOTIFY => ['enabled' => $on]]);
        System::clearRequestSession();
    }

    public function testThePayloadAndTheCard(): void
    {
        $sender = TeamsWorkflowSender::make('user1@example.com', '課題が更新されました（ISS00423）', "**ISS00423** の担当になりました。\n\n1. 状態：対応中\n2. 期日：12/5", [
            'url' => 'https://exment.example.com/admin/data/pm_issue/423',
            'sender' => '野呂',
            'locale' => 'ja',
            'workflow_url' => static::URL,
        ]);
        $payload = $sender->payload();
        $this->assertSame(['to_email', 'title', 'body', 'url', 'sender', 'locale', 'card'], array_keys($payload));
        $this->assertSame('user1@example.com', $payload['to_email']);
        $this->assertSame('ja', $payload['locale']);

        $card = $payload['card'];
        $this->assertSame('AdaptiveCard', $card['type']);
        $this->assertSame('1.4', $card['version']);
        $this->assertSame('Bolder', $card['body'][0]['weight'], 'the title in bold');
        $this->assertSame('課題が更新されました（ISS00423）', $card['body'][0]['text']);
        $texts = array_column($card['body'], 'text');
        $this->assertContains('**ISS00423** の担当になりました。', $texts);
        $this->assertContains("1. 状態：対応中\n\n2. 期日：12/5", $texts, 'a numbered list stays one block');
        $this->assertSame('Action.OpenUrl', $card['actions'][0]['type']);
        $this->assertSame('https://exment.example.com/admin/data/pm_issue/423', $card['actions'][0]['url']);
        $this->assertSame(trans('exment::exment.notify.teams_open', [], 'ja'), $card['actions'][0]['title']);
        $this->assertStringNotContainsString('TOPSECRET', strval(json_encode($payload)), 'the address is not in what is sent');
    }

    public function testAnHtmlMailHasAReadableTextPart(): void
    {
        $html = '<p><strong>課題が更新されました（ISS00423）</strong></p><p><a href="https://exment.example.com/i/423">https://exment.example.com/i/423</a></p>'
            . '<ol><li>状態：対応中</li><li>期日：12/5</li></ol><p>野呂 &amp; 開発<br>以上</p>';
        $this->assertSame(
            "課題が更新されました（ISS00423）\n\nhttps://exment.example.com/i/423\n\n1. 状態：対応中\n2. 期日：12/5\n\n野呂 & 開発\n以上",
            \Exceedone\Exment\Notifications\Mail\MailChannel::htmlToText($html)
        );
        $this->assertSame('開く (https://e.example.com/x?a=1&b=2)', \Exceedone\Exment\Notifications\Mail\MailChannel::htmlToText('<a href="https://e.example.com/x?a=1&amp;b=2">開く</a>'));
    }

    public function testAnHtmlBodyLosesItsTags(): void
    {
        $this->assertSame(['Line one', 'Line two'], TeamsWorkflowSender::paragraphs('<p>Line one</p><p></p><p>Line two</p>'));
    }

    public function testItIsPostedAndTheAddressIsNotLogged(): void
    {
        Http::fake(['flow.example.com/*' => Http::response('', 500)]);
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = strval($message->message);
        });

        $sender = TeamsWorkflowSender::make('user1@example.com', 'title', 'body', ['workflow_url' => static::URL]);
        $sender->send();

        Http::assertSent(function (HttpRequest $request) {
            return $request->url() === static::URL && $request['to_email'] === 'user1@example.com' && isset($request['card']);
        });
        $this->assertSame(500, $sender->status());
        $this->assertNotEmpty($logged, 'a failure is logged');
        foreach ($logged as $line) {
            $this->assertStringNotContainsString('TOPSECRET', $line);
            $this->assertStringNotContainsString('secret-path', $line);
        }
    }

    public function testNothingIsSentWhileTheFeatureIsOffOrWithoutTheAddress(): void
    {
        Http::fake();
        $user = CustomTable::getEloquent('user')->getValueModel()->newQuery()->get()->first(function ($user) {
            return !is_nullorempty($user->getValue('email'));
        });
        $this->assertInstanceOf(\Exceedone\Exment\Model\CustomValue::class, $user, 'a user of the test data with a mail address');
        $params = ['user' => $user, 'subject' => 'subject', 'body' => 'body', 'custom_value' => null];

        System::system_teams_workflow_url(static::URL);
        $this->useFeature(false);
        $this->assertNull(NotifyService::notifyTeamsPersonal($params), 'the feature is off');
        Http::assertNothingSent();

        $this->useFeature(true);
        System::system_teams_workflow_url(null);
        System::clearCache();
        $this->assertNull(NotifyService::notifyTeamsPersonal($params), 'no address');
        Http::assertNothingSent();

        System::system_teams_workflow_url(static::URL);
        System::clearCache();
        $sender = NotifyService::notifyTeamsPersonal($params);
        $this->assertInstanceOf(TeamsWorkflowSender::class, $sender);
        Http::assertSent(function (HttpRequest $request) use ($user) {
            return $request['to_email'] === $user->getValue('email');
        });
    }

    public function testTheActionIsAUserTargetChosenOnlyWhileTheFeatureIsUsed(): void
    {
        $this->assertTrue(NotifyAction::isUserTarget(NotifyAction::TEAMS_PERSONAL));
        $this->assertFalse(NotifyAction::isChatMessage(NotifyAction::TEAMS_PERSONAL));

        $this->useFeature(false);
        $this->assertArrayNotHasKey(NotifyAction::TEAMS_PERSONAL, NotifyAction::selectOptions());
        $this->useFeature(true);
        $this->assertArrayHasKey(NotifyAction::TEAMS_PERSONAL, NotifyAction::selectOptions());
        foreach (['en', 'ja', 'de', 'es', 'ko', 'vi', 'zh_CN'] as $locale) {
            foreach (['notify.notify_action_options.teams_personal', 'notify.teams_open', 'notify.help.teams_personal', 'feature.features.teams_notify', 'system.system_teams_workflow_url_help'] as $key) {
                $this->assertStringStartsNotWith('exment::', strval(trans('exment::exment.' . $key, [], $locale)), "$key in $locale");
            }
        }
    }
}
