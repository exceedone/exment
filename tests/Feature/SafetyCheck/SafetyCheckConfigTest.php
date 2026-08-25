<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckDefine;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestTrait;

class SafetyCheckConfigTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testDefaults()
    {
        $this->assertFalse(boolval(System::safety_check_auto_enabled()));
        $this->assertEquals(45, (int) System::safety_check_min_scale());
        $this->assertEquals(60, (int) System::safety_check_cooldown_minutes());
        $this->assertEquals(60, (int) System::safety_check_comment_window_minutes());
        $this->assertEquals(30, (int) System::safety_check_max_bulletin_age_minutes());
        $this->assertEquals(5, (int) System::safety_check_resend_throttle_minutes());
        $this->assertEquals(20, (int) System::safety_check_index_limit());
    }

    public function testSetAndGet()
    {
        System::safety_check_auto_enabled(true);
        System::safety_check_min_scale(50);
        System::safety_check_last_feed_time('2026-08-11 10:00:00');
        System::clearCache();

        $this->assertTrue(boolval(System::safety_check_auto_enabled()));
        $this->assertEquals(50, (int) System::safety_check_min_scale());
        $this->assertEquals('2026-08-11 10:00:00', (string) System::safety_check_last_feed_time());
    }

    public function testScaleOptions()
    {
        $this->assertArrayHasKey(45, SafetyCheckDefine::scaleOptions());
        $this->assertCount(6, SafetyCheckDefine::scaleOptions());
    }

    /**
     * scaleLabel() renders the intensity for END USERS (Flex card, mail, web answer
     * page, admin list) -- the raw feed code must never reach them. Asserted against
     * literal strings in BOTH locales on purpose: comparing exmtrans() to exmtrans()
     * would still pass if the whole mapping were deleted.
     */
    public function testScaleLabelRendersIntensityNotRawCode()
    {
        \App::setLocale('ja');
        $this->assertEquals('最大震度1', SafetyCheckDefine::scaleLabel(10));
        $this->assertEquals('最大震度3', SafetyCheckDefine::scaleLabel(30));
        $this->assertEquals('最大震度5弱', SafetyCheckDefine::scaleLabel(45));
        $this->assertEquals('最大震度5強', SafetyCheckDefine::scaleLabel(50));
        $this->assertEquals('最大震度7', SafetyCheckDefine::scaleLabel(70));

        // codes that exist in the feed but are NOT threshold choices
        $this->assertEquals('最大震度5弱以上', SafetyCheckDefine::scaleLabel(46));
        $this->assertEquals('最大震度不明', SafetyCheckDefine::scaleLabel(-1));

        \App::setLocale('en');
        $this->assertEquals('Max Shindo 5 Lower', SafetyCheckDefine::scaleLabel(45));
        $this->assertEquals('Max Shindo Unknown', SafetyCheckDefine::scaleLabel(-1));
    }

    /** An unrecognised code must surface as-is, never be swallowed into a wrong label. */
    public function testScaleLabelKeepsUnknownCodeVisible()
    {
        $this->assertStringContainsString('99', SafetyCheckDefine::scaleLabel(99));
    }

    /**
     * The feed-time parsing changed semantics (JST parse -> app-tz convert), so a
     * cursor stored by the OLD code sits hours in the future and would make the
     * watcher skip every bulletin until wall clock passes it. Deploys carrying that
     * change must reset the cursor once; the stale-bulletin guard (max_bulletin_age)
     * prevents re-blasting old quakes on the next poll.
     */
    public function testFeedCursorResetMigrationClearsStoredCursor()
    {
        System::safety_check_last_feed_time('2099-01-01 00:00:00');
        System::clearCache();
        $this->assertNotNull(System::safety_check_last_feed_time());

        $migration = require exment_package_path('database/migrations/2026_08_19_000001_reset_safety_check_feed_cursor.php');
        $migration->up();

        $this->assertNull(System::safety_check_last_feed_time(), 'The migration must clear the stored feed cursor.');
    }
}
