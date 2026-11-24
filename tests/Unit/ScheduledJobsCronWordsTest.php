<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\ScheduledJobs\CronWords;

/**
 * A cron expression in words, for the page "Scheduled jobs" (ISS02832).
 */
class ScheduledJobsCronWordsTest extends UnitTestBase
{
    protected string $locale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->locale = \Illuminate\Support\Facades\App::getLocale();
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\App::setLocale($this->locale);
        parent::tearDown();
    }

    public function testJapanese(): void
    {
        \Illuminate\Support\Facades\App::setLocale('ja');
        $cases = [
            '* * * * *' => '毎分',
            '*/5 * * * *' => '5 分おき',
            '0 * * * *' => '毎時',
            '15 * * * *' => '毎時 15 分',
            '30 */2 * * *' => '2 時間おき（30 分）',
            '30 3 * * *' => '毎日 3:30',
            '0 9,18 * * *' => '毎日 9:00、18:00',
            '0 8 * * 1-5' => '毎週 月曜、火曜、水曜、木曜、金曜 8:00',
            '0 8 * * 0,6' => '毎週 土曜、日曜 8:00',
            '0 8 * * 7' => '毎週 日曜 8:00',
            '0 4 1 * *' => '毎月 1 日 4:00',
            '0 4 1,15 * *' => '毎月 1、15 日 4:00',
            '0 0 1 1 *' => '毎年 1 月 1 日 0:00',
            '0 9-17 * * *' => 'その他（0 9-17 * * *）',
            '* * * *' => 'その他（* * * *）',
            '61 * * * *' => 'その他（61 * * * *）',
            '0 0 1 */3 *' => 'その他（0 0 1 */3 *）',
        ];
        foreach ($cases as $expression => $words) {
            $this->assertSame($words, CronWords::describe($expression), $expression);
        }
    }

    public function testEnglish(): void
    {
        \Illuminate\Support\Facades\App::setLocale('en');
        $this->assertSame('Every minute', CronWords::describe('* * * * *'));
        $this->assertSame('Every 10 minutes', CronWords::describe('*/10 * * * *'));
        $this->assertSame('Every hour', CronWords::describe('0 * * * *'));
        $this->assertSame('Every day at 3:30', CronWords::describe('30 3 * * *'));
        $this->assertSame('Every Monday, Friday at 7:05', CronWords::describe('5 7 * * 5,1'));
        $this->assertSame('Custom (*/5 9-17 * * 1-5)', CronWords::describe('*/5 9-17 * * 1-5'));
    }

    public function testTheJobsOfExmentHaveWords(): void
    {
        \Illuminate\Support\Facades\App::setLocale('ja');
        // the expressions of ExmentServiceProvider::registerSchedule
        $this->assertSame('毎時', CronWords::describe('0 * * * *'));
        $this->assertSame('毎日 3:30', CronWords::describe('30 3 * * *'));
        $this->assertSame('毎分', CronWords::describe('* * * * *'));
    }
}
