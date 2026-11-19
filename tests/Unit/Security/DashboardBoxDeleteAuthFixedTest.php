<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;

/**
 * FIXED: a dashboard box could be deleted by any logged-in user who knew its suuid
 * (data-suuid of the box on a shared dashboard) — DashboardBoxController::delete() checked
 * nothing. The delete icon shows to the dashboard's editors alone; the request now answers
 * 403 to everyone else (Dashboard::hasEditPermission, a dashboard the user cannot view
 * resolving to null through Dashboard's showable scope).
 *
 * The runtime checks build a user dashboard of LoginUser 1 and roll it back.
 */
class DashboardBoxDeleteAuthFixedTest extends SecurityRegressionTestCase
{
    public function test_source_requires_edit_permission_before_deleting(): void
    {
        $src = $this->exmentSource('Controllers/DashboardBoxController.php');
        $this->assertMatchesRegularExpression(
            '/function delete\(.*?hasEditPermission\(\).*?403.*?\$box->delete\(\);/s',
            $src,
            'delete() must answer 403 without the dashboard edit permission, before the box is deleted.'
        );
    }

    public function test_another_user_cannot_delete_a_box_of_a_private_dashboard(): void
    {
        [$owner, $other] = $this->ownerAndOther();
        $box = $this->boxOf($owner);

        $response = $this->as($other)->delete($this->deleteUrl($box));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($response->json('status'));
        $this->assertNotNull(DashboardBox::find($box->id), 'the box must still be there');
    }

    public function test_the_owner_still_deletes(): void
    {
        [$owner] = $this->ownerAndOther();
        $box = $this->boxOf($owner);

        $response = $this->as($owner)->delete($this->deleteUrl($box));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->json('status'));
        $this->assertNull(DashboardBox::find($box->id));
    }

    /**
     * @return array{0: LoginUser, 1: LoginUser}
     */
    private function ownerAndOther(): array
    {
        $owner = LoginUser::find(1);
        $other = LoginUser::where('id', '<>', 1)->orderBy('id')->first();
        if ($owner === null || $other === null) {
            $this->markTestSkipped('needs two login users in the dev DB');
        }
        return [$owner, $other];
    }

    private function as(LoginUser $user): self
    {
        app('auth')->forgetGuards();
        System::clearRequestSession();
        return $this->actingAs($user, 'admin')->withoutMiddleware(VerifyCsrfToken::class);
    }

    private function deleteUrl(DashboardBox $box): string
    {
        return '/' . config('admin.route.prefix', 'admin') . '/dashboardbox/delete/' . $box->suuid;
    }

    /** A user dashboard of $owner, not shared, with one chart box (rolled back by the transaction). */
    private function boxOf(LoginUser $owner): DashboardBox
    {
        $this->as($owner);
        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::USER;
        $dashboard->dashboard_name = 'box_delete_auth_' . uniqid();
        $dashboard->dashboard_view_name = 'box delete auth';
        $dashboard->created_user_id = $owner->getUserId();
        $dashboard->options = ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0];
        $dashboard->save();

        $box = new DashboardBox();
        $box->dashboard_id = $dashboard->id;
        $box->dashboard_box_type = DashboardBoxType::CHART;
        $box->dashboard_box_view_name = 'box';
        $box->row_no = 1;
        $box->column_no = 1;
        $box->options = [];
        $box->save();
        return $box;
    }
}
