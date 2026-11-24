<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\Define;

/**
 * Additions to the lists of Define, for an application built on Exment (registered from its
 * service provider): system settings, system menus, the pages of a table, help links and scheduled jobs.
 * Nothing is added by Exment itself, so the lists are Define's as they are.
 */
class CoreExtensions
{
    /** @var array<string, array<string, mixed>> */
    protected static $systemSettings = [];

    /** @var array<string, array<string, mixed>> */
    protected static $systemMenus = [];

    /** @var array<int, array{page: array<string, mixed>, after: string|null}> */
    protected static $tablePages = [];

    /** @var array<int, array<string, mixed>> */
    protected static $helpUrls = [];

    /** @var array<int, \Closure> */
    protected static $schedules = [];

    /** @var array<string, string> command => name (scheduled_jobs.jobs.<name>) */
    protected static $scheduledJobNames = [];

    /**
     * System settings, as Define::SYSTEM_SETTING_NAME_VALUE (name => definition).
     *
     * @param array<string, array<string, mixed>> $settings
     */
    public static function addSystemSettings(array $settings): void
    {
        static::$systemSettings = array_merge(static::$systemSettings, $settings);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function systemSettings(): array
    {
        return array_merge(Define::SYSTEM_SETTING_NAME_VALUE, static::$systemSettings);
    }

    /**
     * System menus, as Define::MENU_SYSTEM_DEFINITION (name => ['uri' => ..., 'icon' => ...]).
     *
     * @param array<string, array<string, mixed>> $menus
     */
    public static function addSystemMenus(array $menus): void
    {
        static::$systemMenus = array_merge(static::$systemMenus, $menus);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function systemMenus(): array
    {
        return array_merge(Define::MENU_SYSTEM_DEFINITION, static::$systemMenus);
    }

    /**
     * A page of a table, as an item of Define::GRID_CHANGE_PAGE_MENULIST ('name', 'href' with
     * :table_name, 'icon', 'roles', 'exmtrans', 'description'), shown after the page $after
     * (at the end when null). 'visible' => fn(?CustomTable $table): bool hides it. Its name is
     * also an endpoint of a table ("name/{table}", see customTableEndpoints()).
     *
     * @param array<string, mixed> $page
     */
    public static function addTablePage(array $page, ?string $after = null): void
    {
        static::$tablePages[] = ['page' => $page, 'after' => $after];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function tablePages(): array
    {
        $pages = Define::GRID_CHANGE_PAGE_MENULIST;
        foreach (static::$tablePages as $added) {
            $index = is_null($added['after']) ? false : collect($pages)->search(function ($page) use ($added) {
                return array_get($page, 'name') === $added['after'];
            });
            if ($index === false) {
                $pages[] = $added['page'];
            } else {
                array_splice($pages, $index + 1, 0, [$added['page']]);
            }
        }
        return $pages;
    }

    /**
     * The endpoints of a table ("column/{table}" ...): Define::CUSTOM_TABLE_ENDPOINTS and the
     * pages added by addTablePage().
     *
     * @return array<int, string>
     */
    public static function customTableEndpoints(): array
    {
        $added = collect(static::$tablePages)->map(function ($added) {
            return strval(array_get($added['page'], 'name'));
        })->filter()->values()->toArray();
        return array_values(array_unique(array_merge(Define::CUSTOM_TABLE_ENDPOINTS, $added)));
    }

    /**
     * Help links, as Define::HELP_URLS (['uri' => ..., 'help_uri' => ...]). They come first.
     *
     * @param array<int, array<string, mixed>> $urls
     */
    public static function addHelpUrls(array $urls): void
    {
        static::$helpUrls = array_merge(static::$helpUrls, $urls);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function helpUrls(): array
    {
        return array_merge(static::$helpUrls, Define::HELP_URLS);
    }

    /**
     * Scheduled jobs (php artisan schedule:run, and the page "Scheduled jobs"): $schedule is called
     * with the Illuminate\Console\Scheduling\Schedule, after the jobs of Exment. $names gives each
     * command its name on the page, as ScheduledJobs::JOBS: the text scheduled_jobs.jobs.<name>.
     *
     * @param \Closure $schedule function (Schedule $schedule): void
     * @param array<string, string> $names command => name
     */
    public static function addSchedule(\Closure $schedule, array $names = []): void
    {
        static::$schedules[] = $schedule;
        static::$scheduledJobNames = array_merge(static::$scheduledJobNames, $names);
    }

    /**
     * @return array<int, \Closure>
     */
    public static function schedules(): array
    {
        return static::$schedules;
    }

    /**
     * @return array<string, string> command => name, Exment's first
     */
    public static function scheduledJobNames(): array
    {
        return array_merge(static::$scheduledJobNames, \Exceedone\Exment\Services\ScheduledJobs\ScheduledJobs::JOBS);
    }

    /**
     * Forget the additions (tests).
     */
    public static function reset(): void
    {
        static::$systemSettings = [];
        static::$systemMenus = [];
        static::$tablePages = [];
        static::$helpUrls = [];
        static::$schedules = [];
        static::$scheduledJobNames = [];
    }
}
