<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Step 4 of the workflow settings, the notifies of a workflow (admin/workflow/{workflow_id}/notify),
 * and the mail template lookup the notify forms call when the trigger changes
 * (admin/notify/notifytrigger_template).
 */
class WorkflowNotifyCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    public function testWorkflowNotifyScreensAndCrud(): void
    {
        $workflow = Workflow::where('workflow_view_name', 'workflow_common_company')->firstOrFail();
        $base = "workflow/{$workflow->id}/notify";
        $template = $this->mailTemplateId(MailKeyName::WORKFLOW_NOTIFY);

        $this->get(admin_url("{$base}/create"))
            ->assertOk()
            ->assertSee('name="notify_view_name"', false)
            ->assertSeeText('workflow_common_company');

        $this->post(admin_url($base), $this->payload('Claude workflow notify', $template))
            ->assertRedirect(admin_url($base))
            ->assertSessionHasNoErrors();
        $notify = Notify::where('notify_view_name', 'Claude workflow notify')->first();
        if ($notify === null) {
            $this->fail('the workflow notify must be stored');
        }
        // the trigger and the workflow come from the screen, not from the post
        $this->assertDatabaseHas('notifies', [
            'id' => $notify->id,
            'target_id' => $workflow->id,
            'notify_trigger' => (int) NotifyTrigger::WORKFLOW,
            'mail_template_id' => $template,
        ]);
        $this->assertSame(NotifyActionTarget::WORK_USER, $notify->action_settings[0]['notify_action_target'][0] ?? null);

        $this->get(admin_url($base))->assertOk()->assertSeeText('Claude workflow notify');
        // there is no detail page: show opens the edit form
        $this->get(admin_url("{$base}/{$notify->id}"))->assertRedirect(admin_url("{$base}/{$notify->id}/edit"));
        $this->get(admin_url("{$base}/{$notify->id}/edit"))
            ->assertOk()
            ->assertSee('value="Claude workflow notify"', false);

        $this->put(admin_url("{$base}/{$notify->id}"), $this->payload('Claude workflow notify (put)', $template))
            ->assertRedirect(admin_url($base))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('notifies', ['id' => $notify->id, 'notify_view_name' => 'Claude workflow notify (put)']);

        $this->patch(admin_url("{$base}/{$notify->id}"), $this->payload('Claude workflow notify (patch)', $template))
            ->assertRedirect(admin_url($base))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('notifies', ['id' => $notify->id, 'notify_view_name' => 'Claude workflow notify (patch)']);

        $this->delete(admin_url("{$base}/{$notify->id}"))
            ->assertOk()
            ->assertJson(['status' => true]);
        $this->assertDatabaseMissing('notifies', ['id' => $notify->id]);
    }

    public function testNotifyActionTargetOffersTheWorkflowTargets(): void
    {
        $workflow = Workflow::where('workflow_view_name', 'workflow_common_company')->firstOrFail();

        $this->get(admin_url("workflow/{$workflow->id}/notify/notify_action_target?q=" . NotifyAction::SHOW_PAGE))
            ->assertOk()
            ->assertJsonFragment([
                'id' => NotifyActionTarget::WORK_USER,
                'text' => exmtrans('notify.notify_action_target_options.work_user'),
            ])
            ->assertJsonFragment([
                'id' => NotifyActionTarget::ACTION_USER,
                'text' => exmtrans('notify.notify_action_target_options.action_user'),
            ]);
    }

    public function testNotifyTriggerTemplateAnswersTheDefaultMailTemplate(): void
    {
        $this->post(admin_url('notify/notifytrigger_template'), ['value' => NotifyTrigger::WORKFLOW])
            ->assertOk()
            ->assertExactJson(['mail_template_id' => $this->mailTemplateId(MailKeyName::WORKFLOW_NOTIFY)]);

        $this->post(admin_url('notify/notifytrigger_template'), ['value' => NotifyTrigger::TIME])
            ->assertOk()
            ->assertExactJson(['mail_template_id' => $this->mailTemplateId(MailKeyName::TIME_NOTIFY)]);

        // a button notify has no default template
        $this->post(admin_url('notify/notifytrigger_template'), ['value' => NotifyTrigger::BUTTON])
            ->assertOk()
            ->assertExactJson(['mail_template_id' => null]);
    }

    /**
     * What the workflow notify form posts.
     *
     * @param mixed $mail_template_id
     * @return array<string, mixed>
     */
    protected function payload(string $name, $mail_template_id): array
    {
        // every hasManyJson item needs its _remove_ key (NestedForm reads it unguarded)
        return [
            'notify_view_name' => $name,
            'active_flg' => 1,
            'trigger_settings' => ['notify_myself' => 0],
            'action_settings' => ['new_1' => [
                'notify_action' => NotifyAction::SHOW_PAGE,
                'notify_action_target' => [NotifyActionTarget::WORK_USER],
                '_remove_' => 0,
            ]],
            'mail_template_id' => $mail_template_id,
        ];
    }

    /**
     * @return mixed id of the mail template of the key
     */
    protected function mailTemplateId(string $mail_key_name)
    {
        $template = CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE)
            ->getValueModel()
            ->where('value->mail_key_name', $mail_key_name)
            ->first();
        if ($template === null) {
            $this->fail("the test data must have the {$mail_key_name} mail template");
        }

        return $template->id;
    }
}
