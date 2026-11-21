<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\WorkflowWorkTargetType;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Tests\DatabaseTransactions;

/**
 * The users of the next step of a workflow come from the request (next_work_users) only when
 * the next step is picked at the action (ACTION_SELECT), and only as users or organizations that
 * exist. Otherwise the request could share the record with anyone.
 */
class WorkflowNextUsersTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    /**
     * @param mixed $status_to
     * @param mixed $selected
     * @return array<string>
     */
    protected function filter(WorkflowAction $action, $status_to, $selected): array
    {
        $method = new \ReflectionMethod(WorkflowAction::class, 'filterSelectedNextWorkUsers');
        $method->setAccessible(true);
        return $method->invoke($action, $status_to, $selected);
    }

    /**
     * An action and the status it leads to, whose next actions are (or are not) ACTION_SELECT.
     *
     * @return array{0: WorkflowAction, 1: mixed}|null
     */
    protected function findAction(bool $select): ?array
    {
        foreach (WorkflowAction::all() as $action) {
            $workflow = $action->workflow;
            if (!$workflow) {
                continue;
            }
            // every status of the workflow, and the start
            $statuses = WorkflowStatus::where('workflow_id', $workflow->id)->pluck('id')->all();
            $statuses[] = null;
            foreach ($statuses as $status_to) {
                $next = WorkflowStatus::getActionsByFrom($status_to, $workflow);
                if ($next->count() == 0) {
                    continue;
                }
                $has = $next->contains(function ($n) {
                    return $n->getOption('work_target_type') == WorkflowWorkTargetType::ACTION_SELECT;
                });
                if ($has === $select) {
                    return [$action, $status_to];
                }
            }
        }
        return null;
    }

    public function testTheRequestDoesNotPickTheNextUsersWhenTheWorkflowDoes(): void
    {
        $found = $this->findAction(false);
        $this->assertNotNull($found, 'the test data has a step whose next users the workflow decides');
        [$action, $status_to] = $found;

        $this->assertSame([], $this->filter($action, $status_to, ['user_1', 'user_2']));
    }

    public function testThePickedUsersAreKeptWhenTheNextStepIsPickedAtTheAction(): void
    {
        $found = $this->findAction(true);
        if (!$found) {
            $this->markTestSkipped('no ACTION_SELECT step in the test data');
        }
        [$action, $status_to] = $found;

        $this->assertSame(['user_1'], $this->filter($action, $status_to, ['user_1', 'user_999999', 'evil_1', 'user_1;x']));
    }
}
