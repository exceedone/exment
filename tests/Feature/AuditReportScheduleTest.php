<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Model\AuditReportFile;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\AuditReport\AuditReport;
use Exceedone\Exment\Services\AuditReport\AuditReportStore;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;

/**
 * The IT audit report made by the job each month or quarter, the record of each PDF made with its
 * SHA-256 and the check of a PDF, the settings, the security state (ISS02840). The rows are rolled
 * back; the files the job kept are deleted.
 */
class AuditReportScheduleTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    /** @var array<int, string> the files kept by the job in a test */
    protected $kept = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
    }

    protected function tearDown(): void
    {
        foreach (AuditReportFile::query()->whereNotNull('file')->get() as $record) {
            $path = AuditReportStore::path($record);
            if (isset($path)) {
                @unlink($path);
            }
        }
        System::audit_report_schedule(null);
        System::audit_report_mail(false);
        System::clearCache();
        parent::tearDown();
    }

    /**
     * @param int|string $id the login user of the test data (TestDefine)
     */
    protected function loginAs($id): void
    {
        /** @var LoginUser $user */
        $user = LoginUser::findOrFail($id);
        $this->be($user);
        System::clearRequestSession();
    }

    public function testTheDuePeriod(): void
    {
        $now = Carbon::parse('2026-11-26 04:10:00');
        System::audit_report_schedule(AuditReportStore::SCHEDULE_OFF);
        System::clearCache();
        $this->assertNull(AuditReportStore::duePeriod($now));

        System::audit_report_schedule(AuditReportStore::SCHEDULE_MONTHLY);
        System::clearCache();
        [$from, $to] = AuditReportStore::duePeriod($now->copy()) ?? [null, null];
        $this->assertSame(['2026-10-01', '2026-10-31'], [optional($from)->format('Y-m-d'), optional($to)->format('Y-m-d')]);

        System::audit_report_schedule(AuditReportStore::SCHEDULE_QUARTERLY);
        System::clearCache();
        [$from, $to] = AuditReportStore::duePeriod($now->copy()) ?? [null, null];
        $this->assertSame(['2026-07-01', '2026-09-30'], [optional($from)->format('Y-m-d'), optional($to)->format('Y-m-d')]);
    }

    public function testTheJobMakesTheReportOnce(): void
    {
        // off: nothing
        System::audit_report_schedule(AuditReportStore::SCHEDULE_OFF);
        System::clearCache();
        $this->assertSame(0, Artisan::call('exment:audit-report'));
        $this->assertSame(0, AuditReportFile::query()->where('source', AuditReportFile::SOURCE_SCHEDULE)->count());

        System::audit_report_schedule(AuditReportStore::SCHEDULE_MONTHLY);
        System::clearCache();
        $admin = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $bells = NotifyNavbar::query()->where('target_user_id', $admin->base_user_id)->count();

        $this->assertSame(0, Artisan::call('exment:audit-report'));
        $record = AuditReportFile::query()->where('source', AuditReportFile::SOURCE_SCHEDULE)->firstOrFail();
        [$from] = AuditReportStore::duePeriod() ?? [null];
        $this->assertSame(optional($from)->format('Y-m-d'), $record->period_from->format('Y-m-d'));
        $this->assertNull($record->made_user_id);
        $path = AuditReportStore::path($record);
        $this->assertNotNull($path, 'the file is kept');
        $this->assertSame($record->sha256, hash_file('sha256', strval($path)));
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame('600', substr(sprintf('%o', fileperms(strval($path))), -3));
        }
        // the system administrator is told (no file in the notice)
        $this->assertSame($bells + 1, NotifyNavbar::query()->where('target_user_id', $admin->base_user_id)->count());
        $bell = NotifyNavbar::query()->where('target_user_id', $admin->base_user_id)->orderByDesc('id')->firstOrFail();
        $this->assertStringContainsString($record->sha256, strval($bell->notify_body));

        // once a period
        $this->assertSame(0, Artisan::call('exment:audit-report'));
        $this->assertSame(1, AuditReportFile::query()->where('source', AuditReportFile::SOURCE_SCHEDULE)->count());

        // the file, from the page
        $download = $this->get(admin_urls('log_management', 'audit', 'files', $record->id));
        $download->assertOk();
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        $this->get(admin_urls('log_management', 'audit', 'files', '999999'), static::AJAX)->assertNotFound();
    }

    public function testAPdfMadeIsRecordedAndChecked(): void
    {
        $pdf = $this->get(admin_urls_query('log_management', 'audit.pdf', ['from' => '2020-01-01', 'to' => '2020-03-31']));
        $pdf->assertOk();
        $body = strval($pdf->getContent());
        $hash = hash('sha256', $body);
        $this->assertSame($hash, $pdf->headers->get('X-Content-SHA256'));
        $record = AuditReportFile::query()->where('sha256', $hash)->firstOrFail();
        $this->assertSame(AuditReportFile::SOURCE_PAGE, $record->source);
        $this->assertNull($record->file, 'the page keeps only the hash');
        $this->assertSame('2020-01-01', $record->period_from->format('Y-m-d'));

        // the same file: made here, not changed
        $this->post(admin_urls('log_management', 'audit', 'verify'), ['report' => UploadedFile::fake()->createWithContent('report.pdf', $body)])->assertRedirect();
        $this->assertSame('success', session('toastr')->get('type')[0] ?? null);
        // a file changed
        $this->post(admin_urls('log_management', 'audit', 'verify'), ['report' => UploadedFile::fake()->createWithContent('report.pdf', $body . ' ')])->assertRedirect();
        $this->assertSame('error', session('toastr')->get('type')[0] ?? null);

        // listed on the page
        $this->get(admin_urls('log_management', 'audit'))->assertOk()->assertSee($hash);
    }

    public function testTheSettings(): void
    {
        $this->post(admin_urls('log_management', 'audit', 'setting'), ['audit_report_schedule' => AuditReportStore::SCHEDULE_QUARTERLY, 'audit_report_mail' => '1'])->assertRedirect();
        System::clearCache();
        $this->assertSame(AuditReportStore::SCHEDULE_QUARTERLY, AuditReportStore::schedule());
        $this->assertTrue(boolval(System::audit_report_mail()));
        $this->post(admin_urls('log_management', 'audit', 'setting'), ['audit_report_schedule' => 'daily'])->assertSessionHasErrors('audit_report_schedule');

        // not a system administrator: not changed
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->post(admin_urls('log_management', 'audit', 'setting'), ['audit_report_schedule' => AuditReportStore::SCHEDULE_OFF], static::AJAX);
        System::clearCache();
        $this->assertSame(AuditReportStore::SCHEDULE_QUARTERLY, AuditReportStore::schedule());
    }

    public function testTheSecurityStateAndTheAuditors(): void
    {
        $report = new AuditReport(Carbon::now()->subMonth(), Carbon::now());
        $rows = $report->section('security')['rows'];
        $this->assertSame(exmtrans('audit_report.security.version'), $rows[0][0] ?? null);
        $this->assertStringStartsWith('PHP ', $rows[0][2] ?? '');
        $this->assertSame(exmtrans('audit_report.security.last_backup'), $rows[count($rows) - 1][0] ?? null);

        $admin = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $user1 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertContains(intval($admin->base_user_id), $report->auditorIds());
        $this->assertNotContains(intval($user1->base_user_id), $report->auditorIds());
    }
}
