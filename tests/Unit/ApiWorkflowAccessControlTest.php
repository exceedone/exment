<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Controllers\ApiWorkflowController;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Workflow;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Regression test for JVN#20312919 (broken access control on workflow API).
 *
 * ApiWorkflowController's workflow-definition read endpoints (get/workflowStatus/
 * workflowAction) must gate table-type workflows on the designated table's
 * access permission, while leaving common-type workflows (which have no
 * designated table) readable within the granted API scope so that legitimate
 * workflow participants are not broken.
 *
 * These assertions exercise the shared guard checkWorkflowAccess() directly and
 * only need the application booted (no database).
 */
class ApiWorkflowAccessControlTest extends TestCase
{
    /**
     * @param bool|ErrorCode $enableAccessReturn
     * @return CustomTable stub whose enableAccess() returns the given value
     */
    private function fakeTable($enableAccessReturn): CustomTable
    {
        return new class($enableAccessReturn) extends CustomTable {
            /** @var bool|ErrorCode */
            private $ret;

            /**
             * @param bool|ErrorCode $ret
             */
            public function __construct($ret)
            {
                $this->ret = $ret;
            }

            /**
             * @return bool|ErrorCode
             */
            public function enableAccess()
            {
                return $this->ret;
            }
        };
    }

    /**
     * @param CustomTable|null $designatedTable
     * @return Workflow
     */
    private function fakeWorkflow(?CustomTable $designatedTable): Workflow
    {
        return new class($designatedTable) extends Workflow {
            /** @var CustomTable|null */
            public $designated;

            /**
             * @param CustomTable|null $designated
             */
            public function __construct($designated)
            {
                $this->designated = $designated;
            }

            /**
             * @return CustomTable|null
             */
            public function getDesignatedTable()
            {
                return $this->designated;
            }
        };
    }

    /**
     * @param Workflow $workflow
     * @return Response|null
     */
    private function invokeGuard(Workflow $workflow)
    {
        $controller = (new ReflectionClass(ApiWorkflowController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ApiWorkflowController::class, 'checkWorkflowAccess');
        $method->setAccessible(true);
        return $method->invoke($controller, $workflow);
    }

    /**
     * Common-type workflows have no designated table and must stay readable
     * (returning null = allowed) so workflow participants are not broken.
     *
     * @return void
     */
    public function testCommonTypeWorkflowIsAllowed()
    {
        $result = $this->invokeGuard($this->fakeWorkflow(null));
        $this->assertNull($result);
    }

    /**
     * Table-type workflow whose designated table grants access is allowed.
     *
     * @return void
     */
    public function testTableTypeWithAccessIsAllowed()
    {
        $result = $this->invokeGuard($this->fakeWorkflow($this->fakeTable(true)));
        $this->assertNull($result);
    }

    /**
     * Table-type workflow whose designated table denies access must be blocked
     * with a 403 response.
     *
     * @return void
     */
    public function testTableTypeWithoutAccessIsBlocked()
    {
        $result = $this->invokeGuard($this->fakeWorkflow($this->fakeTable(ErrorCode::PERMISSION_DENY())));
        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(403, $result->getStatusCode());
    }
}
