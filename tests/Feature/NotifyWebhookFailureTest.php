<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * A Slack / Teams message that can not be sent (the webhook does not answer, or answers an
 * error) is logged and shown as a warning, as a mail that can not be sent is: the data that
 * was saved stays saved, and the other actions of the notify are still done.
 * No request leaves the test: the HTTP client of the webhook channels is a mock.
 */
class NotifyWebhookFailureTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const HOST = 'chat.example.test';
    private const URL_OK_SLACK = 'https://chat.example.test/ok/slack';
    private const URL_OK_TEAMS = 'https://chat.example.test/ok/teams';
    private const URL_DOWN = 'https://chat.example.test/down';
    private const URL_ERROR_500 = 'https://chat.example.test/error500';
    private const URL_BROKEN = 'https://chat.example.test/broken';

    /** @var array<int, RequestInterface> the requests to the webhooks */
    private $requests = [];

    /** @var \Mockery\MockInterface */
    private $log;

    /** @var CustomValue */
    private $user1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        config(['exment.notify_webhook_allow_hosts' => self::HOST]);

        // the webhook channels take GuzzleHttp\Client from the container. A failed connection is
        // returned (a rejected promise, as curl answers it).
        $mock = new MockHandler(array_fill(0, 20, function (RequestInterface $request) {
            $this->requests[] = $request;
            $path = $request->getUri()->getPath();
            if ($path === '/down') {
                return new ConnectException('cURL error 7: Failed to connect', $request);
            }
            if ($path === '/error500') {
                return new Response(500, [], 'error');
            }
            if ($path === '/broken') {
                throw new \RuntimeException('not an error of the sending');
            }
            return new Response(200, [], 'ok');
        }));
        $this->app->instance(Client::class, new Client(['handler' => HandlerStack::create($mock)]));

        $admin = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertInstanceOf(LoginUser::class, $admin);
        $this->be($admin);

        $user1 = getModelName(SystemTableName::USER)::where('value->user_code', 'user1')->first();
        $this->assertNotNull($user1);
        $this->user1 = $user1;
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    /**
     * @param array<int, array<string, mixed>> $actions
     */
    private function createNotify(array $actions, string $trigger = NotifyTrigger::CREATE_UPDATE_DATA, ?int $targetId = null): Notify
    {
        $notify = new Notify();
        $notify->notify_view_name = 'notify_webhook_failure_' . count($actions) . '_' . $trigger;
        $notify->target_id = $targetId ?? CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL)->id;
        $notify->notify_trigger = $trigger;
        $notify->mail_template_id = 6;
        $notify->active_flg = 1;
        $notify->trigger_settings = $trigger === NotifyTrigger::CREATE_UPDATE_DATA
            ? ['notify_saved_trigger' => ['created', 'updated']]
            : [];
        $notify->action_settings = $actions;
        $notify->save();
        System::clearCache();
        return $notify;
    }

    /**
     * @return array<string, mixed>
     */
    private function chat(string $action, string $url): array
    {
        return ['notify_action' => $action, 'webhook_url' => $url];
    }

    /**
     * @return array<string, mixed>
     */
    private function toUser1(string $action): array
    {
        return [
            'notify_action' => $action,
            'notify_action_target' => [NotifyActionTarget::FIXED_USER],
            'target_users' => [$this->user1->id],
        ];
    }

    /**
     * @return array<int, string> the paths the webhooks were posted to
     */
    private function postedPaths(): array
    {
        return array_map(function (RequestInterface $request) {
            return $request->getUri()->getPath();
        }, $this->requests);
    }

    private function navbarCount(Notify $notify): int
    {
        return NotifyNavbar::withoutGlobalScopes()->where('notify_id', $notify->id)->where('target_user_id', $this->user1->id)->count();
    }

    private function mailLogCount(): int
    {
        return CustomTable::getEloquent(SystemTableName::MAIL_SEND_LOG)->getValueModel()->query()->count();
    }

    /**
     * Save a record as the data form does: in a transaction, the notify in the saved event.
     */
    private function saveInTransaction(string $text): CustomValue
    {
        return \DB::transaction(function () use ($text) {
            $value = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL)->getValueModel();
            $value->setValue('text', $text)->save();
            return $value;
        });
    }

    private function assertWebhookWarning(): void
    {
        $warning = session('warning');
        $this->assertNotNull($warning, 'a warning is shown');
        $this->assertSame(exmtrans('error.header'), $warning->first('title'));
        $this->assertSame(exmtrans('error.webhooksend_failed'), $warning->first('message'));
    }

    private function assertErrorLogged(int $times = 1): void
    {
        $this->log->shouldHaveReceived('error')->times($times)->withArgs(function ($ex) {
            return $ex instanceof GuzzleException;
        });
    }

    // the data form ------------------------------------------------------

    public function testRecordIsSavedOnTheFormWhenTheWebhookIsDown(): void
    {
        $value = $this->saveInTransaction('before webhook down');
        $this->createNotify([$this->chat(NotifyAction::SLACK, self::URL_DOWN)]);
        $this->log = Log::spy();

        $response = $this->put(admin_urls('data', TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL, $value->id), [
            'value' => ['text' => 'after webhook down'],
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(admin_urls('data', TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL));
        $this->assertSame('after webhook down', $value->fresh()->getValue('text'), 'the record is saved');
        $this->assertSame(['/down'], $this->postedPaths());
        $this->assertErrorLogged();
    }

    // notifyUser ---------------------------------------------------------

    public function testSlackDownKeepsTheRecordAndTheOtherActions(): void
    {
        $notify = $this->createNotify([
            $this->chat(NotifyAction::SLACK, self::URL_DOWN),
            $this->chat(NotifyAction::MICROSOFT_TEAMS, self::URL_OK_TEAMS),
            $this->toUser1(NotifyAction::EMAIL),
            $this->toUser1(NotifyAction::SHOW_PAGE),
        ]);
        $mails = $this->mailLogCount();
        $this->log = Log::spy();

        $value = $this->saveInTransaction('slack down');

        $this->assertNotNull(CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL)->getValueModel()->find($value->id), 'the record is saved');
        $this->assertSame(['/down', '/ok/teams'], $this->postedPaths(), 'Teams is sent after Slack failed');
        $this->assertSame($mails + 1, $this->mailLogCount(), 'the mail is sent');
        $this->assertSame(1, $this->navbarCount($notify), 'the alert is made');
        $this->assertErrorLogged();
        $this->assertWebhookWarning();
    }

    public function testTeamsErrorAnswerKeepsTheRecordAndTheOtherActions(): void
    {
        $notify = $this->createNotify([
            $this->chat(NotifyAction::MICROSOFT_TEAMS, self::URL_ERROR_500),
            $this->chat(NotifyAction::SLACK, self::URL_OK_SLACK),
            $this->toUser1(NotifyAction::SHOW_PAGE),
        ]);
        $this->log = Log::spy();

        $value = $this->saveInTransaction('teams 500');

        $this->assertTrue($value->exists);
        $this->assertSame(['/error500', '/ok/slack'], $this->postedPaths());
        $this->assertSame(1, $this->navbarCount($notify));
        $this->assertErrorLogged();
        $this->assertWebhookWarning();
    }

    public function testWebhooksThatAnswerAreSentAsBefore(): void
    {
        $notify = $this->createNotify([
            $this->chat(NotifyAction::SLACK, self::URL_OK_SLACK),
            $this->chat(NotifyAction::MICROSOFT_TEAMS, self::URL_OK_TEAMS),
            $this->toUser1(NotifyAction::SHOW_PAGE),
        ]);
        $this->log = Log::spy();

        $this->saveInTransaction('webhooks ok');

        $this->assertSame(['/ok/slack', '/ok/teams'], $this->postedPaths());
        $slack = json_decode((string)$this->requests[0]->getBody(), true);
        $teams = json_decode((string)$this->requests[1]->getBody(), true);
        $this->assertIsString(array_get($slack, 'text'));
        $this->assertIsString(array_get($teams, 'text'));
        $this->assertSame(1, $this->navbarCount($notify));
        $this->log->shouldNotHaveReceived('error');
        $this->assertNull(session('warning'));
    }

    public function testAnErrorThatIsNotOfTheSendingIsStillThrown(): void
    {
        $this->createNotify([$this->chat(NotifyAction::SLACK, self::URL_BROKEN)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not an error of the sending');
        $this->saveInTransaction('broken');
    }

    public function testMailFailureIsHandledAsBefore(): void
    {
        $this->useFailingMailer();
        $notify = $this->createNotify([
            $this->toUser1(NotifyAction::EMAIL),
            $this->toUser1(NotifyAction::SHOW_PAGE),
            $this->chat(NotifyAction::SLACK, self::URL_OK_SLACK),
        ]);
        $mails = $this->mailLogCount();
        $this->log = Log::spy();

        $value = $this->saveInTransaction('mail down');

        $this->assertTrue($value->exists);
        $this->assertSame($mails, $this->mailLogCount(), 'no mail history for a mail not sent');
        $this->assertSame(1, $this->navbarCount($notify));
        $this->assertSame(['/ok/slack'], $this->postedPaths());
        $this->log->shouldHaveReceived('error')->once()->withArgs(function ($ex) {
            return $ex instanceof TransportException;
        });
        $warning = session('warning');
        $this->assertNotNull($warning);
        $this->assertSame(exmtrans('error.mailsend_failed'), $warning->first('message'));
    }

    private function useFailingMailer(): void
    {
        Mail::extend('failing_for_test', function () {
            return new class () extends AbstractTransport {
                protected function doSend(SentMessage $message): void
                {
                    throw new TransportException('Connection could not be established');
                }

                public function __toString(): string
                {
                    return 'failing_for_test';
                }
            };
        });
        config(['mail.mailers.failing_for_test' => ['transport' => 'failing_for_test'], 'mail.default' => 'failing_for_test']);
        Mail::purge('failing_for_test');
    }

    // notifyButtonClick --------------------------------------------------

    public function testButtonSendsTheOtherActionsWhenTheWebhookIsDown(): void
    {
        $value = $this->saveInTransaction('button');
        $notify = $this->createNotify([
            $this->chat(NotifyAction::SLACK, self::URL_DOWN),
            $this->toUser1(NotifyAction::SHOW_PAGE),
        ], NotifyTrigger::BUTTON);
        $userKey = collect($notify->getNotifyTargetUsers($value, $notify->action_settings[1]))->first()->notifyKey();
        $this->log = Log::spy();

        $notify->notifyButtonClick($value, [$userKey], 'subject', 'body');

        $this->assertSame(['/down'], $this->postedPaths());
        $this->assertSame(1, $this->navbarCount($notify));
        $this->assertErrorLogged();
        $this->assertWebhookWarning();
    }

    // notifyWorkflow -----------------------------------------------------

    public function testWorkflowSendsTheOtherActionsWhenTheWebhookIsDown(): void
    {
        $workflow = Workflow::where('workflow_view_name', 'workflow_common_company')->first();
        $workflowAction = WorkflowAction::where('action_name', 'middle_action')->where('workflow_id', $workflow->id)->first();
        $value = $this->saveInTransaction('workflow');
        $workflowValue = $this->callProtectedMethod($workflowAction, 'forwardWorkflowValue', $value);
        $value = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL)->getValueModel()->find($value->id);
        $this->assertInstanceOf(CustomValue::class, $value);
        $statusTo = $workflowAction->getStatusToId($value);

        $notify = $this->createNotify([
            $this->chat(NotifyAction::MICROSOFT_TEAMS, self::URL_DOWN),
            $this->toUser1(NotifyAction::SHOW_PAGE),
        ], NotifyTrigger::WORKFLOW, $workflow->id);
        $this->log = Log::spy();

        $notify->notifyWorkflow($value, $workflowAction, $workflowValue, $statusTo);

        $this->assertSame(['/down'], $this->postedPaths());
        $this->assertSame(1, $this->navbarCount($notify));
        $this->assertErrorLogged();
        $this->assertWebhookWarning();
    }

    // notifySchedule -----------------------------------------------------

    public function testScheduleSendsTheOtherActionsWhenTheWebhookIsDown(): void
    {
        Carbon::setTestNow(Carbon::now());
        try {
            $notify = Notify::where('notify_trigger', NotifyTrigger::TIME)->first();
            $notify->setTriggerSetting('notify_hour', Carbon::now()->format('G'));
            $notify->setTriggerSetting('notify_day', 365);
            $notify->setTriggerSetting('notify_beforeafter', -1);
            $notify->action_settings = [
                $this->chat(NotifyAction::SLACK, self::URL_DOWN),
                $this->toUser1(NotifyAction::SHOW_PAGE),
            ];
            $notify->save();
            $model = CustomTable::getEloquent($notify->target_id)->getValueModel()->query()->first();
            $model->update(['value->date' => Carbon::today()->addDays(365)->format('Y-m-d')]);
            System::clearCache();
            $this->log = Log::spy();

            $notify->notifySchedule();

            $this->assertContains('/down', $this->postedPaths());
            $this->assertGreaterThanOrEqual(1, $this->navbarCount($notify));
            $this->log->shouldHaveReceived('error')->withArgs(function ($ex) {
                return $ex instanceof GuzzleException;
            });
        } finally {
            Carbon::setTestNow();
        }
    }
}
