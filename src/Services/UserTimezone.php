<?php

namespace Exceedone\Exment\Services;

use Carbon\Carbon;
use Exceedone\Exment\Enums\UserSetting as UserSettingKey;

/**
 * The time zone of a user (ISS02835).
 *
 * The database keeps the date and times in the time zone of the site (APP_TIMEZONE), and PHP runs in it.
 * Only the display and the input change: a date and time is shown in the time zone of the user
 * (toUser / formatToUser), and a date and time the user typed is read in it (fromUser).
 * "Today", "this month" etc. of the conditions are the days of the user (today), and the start of
 * such a day is turned into the time of the site for the SQL (dayStartForQuery).
 * The columns of only a date (and of only a time) are never converted.
 *
 * The time zone of the page is set by the middleware (UserLocaleTimezone) for the web pages; the API,
 * the batches and the queue stay in the time zone of the site.
 */
class UserTimezone
{
    /** the key of the user setting (user_settings.settings.user_setting.timezone) */
    public const SETTING_KEY = UserSettingKey::USER_SETTING . '.timezone';

    /** the format of the database */
    public const DB_FORMAT = 'Y-m-d H:i:s';

    /**
     * The time zone of the display and the input now (null: the time zone of the site).
     *
     * @var string|null
     */
    protected static $current = null;

    /**
     * Import / export now: 'user' (the date and times in the time zone of the user), 'site' (in the time
     * zone of the site, the default), or null (no import / export).
     *
     * @var string|null
     */
    protected static $dataMode = null;

    /**
     * The time zone of the site (APP_TIMEZONE), which the database keeps.
     */
    public static function site(): string
    {
        $timezone = config('exment.timezone') ?: config('app.timezone');
        if (!static::isValid($timezone)) {
            $timezone = date_default_timezone_get();
        }
        return (string)$timezone;
    }

    /**
     * The time zone of the display and the input now.
     */
    public static function user(): string
    {
        return static::$current ?? static::site();
    }

    /**
     * The time zone set for the display (null: the time zone of the site).
     */
    public static function current(): ?string
    {
        return static::$current;
    }

    /**
     * Set the time zone of the display and the input (null or invalid: the time zone of the site).
     */
    public static function setCurrent(?string $timezone): void
    {
        static::$current = static::isValid($timezone) ? $timezone : null;
    }

    /**
     * Whether the time zone of the display is not the time zone of the site.
     * (When it is the same, nothing is converted: the same results as before.)
     */
    public static function differs(): bool
    {
        if (is_null(static::$current)) {
            return false;
        }
        return static::offsetsDiffer(static::$current, static::site());
    }

    /**
     * Run a callback with a time zone of the display, and put the time zone back after it.
     *
     * @param string|null $timezone
     * @param \Closure $callback
     * @return mixed
     */
    public static function runWith(?string $timezone, \Closure $callback)
    {
        $before = static::$current;
        static::setCurrent($timezone);
        try {
            return $callback();
        } finally {
            static::$current = $before;
        }
    }

    /**
     * The time zone a user chose, or the time zone of the site.
     *
     * @param mixed $user LoginUser, a user CustomValue, the id of the user or null
     */
    public static function forUser($user): string
    {
        if (is_null($user)) {
            return static::site();
        }
        $timezone = UserLocale::userSetting($user, static::SETTING_KEY);
        return static::isValid($timezone) ? (string)$timezone : static::site();
    }

    /**
     * The time zones to choose from.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $list = timezone_identifiers_list();
        return array_combine($list, $list);
    }

    public static function isValid(?string $timezone): bool
    {
        if (is_nullorempty($timezone)) {
            return false;
        }
        // the list made once (it was made for each check: once a user of a notification, ISS02906)
        if (!isset(static::$identifiers)) {
            static::$identifiers = array_flip(timezone_identifiers_list());
        }
        return isset(static::$identifiers[$timezone]) || strtoupper((string)$timezone) === 'UTC';
    }

    /**
     * The names of the time zones PHP knows, as keys.
     *
     * @var array<string, int>|null
     */
    protected static $identifiers = null;

    // the conversion ----------------------------------------------------

    /**
     * A date and time of the database (the time zone of the site) in the time zone of the display.
     *
     * @param mixed $value string, DateTimeInterface or null
     */
    public static function toUser($value): ?Carbon
    {
        $carbon = static::parse($value, static::site());
        return $carbon ? $carbon->setTimezone(static::user()) : null;
    }

    /**
     * A date and time the user typed (the time zone of the display) in the time zone of the site.
     *
     * @param mixed $value string, DateTimeInterface or null
     */
    public static function fromUser($value): ?Carbon
    {
        // the time as written (a date of a file etc. has the time zone of PHP, not of the user)
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format(static::DB_FORMAT);
        }
        $carbon = static::parse($value, static::user());
        return $carbon ? $carbon->setTimezone(static::site()) : null;
    }

    /**
     * Format a date and time of the database for the display. The value as it is when nothing converts.
     *
     * @param mixed $value
     * @param string $format
     * @return mixed
     */
    public static function formatToUser($value, string $format = self::DB_FORMAT)
    {
        // an import / export in the time zone of the site: as the database
        if (is_nullorempty($value) || !static::differs() || static::$dataMode === 'site') {
            return $value;
        }
        $carbon = static::toUser($value);
        return $carbon ? $carbon->format($format) : $value;
    }

    /**
     * A date and time of the database for a screen (comments, histories, lists, logs ...).
     * Without a format: the value as it is when nothing converts (as the screens printed it before),
     * else "Y-m-d H:i:s" in the time zone of the display. With a format: always that format.
     *
     * @param mixed $value string, DateTimeInterface or null
     * @param string|null $format
     * @return mixed
     */
    public static function display($value, ?string $format = null)
    {
        if (is_nullorempty($value)) {
            return $value;
        }
        if (!static::differs()) {
            if (is_null($format)) {
                return $value;
            }
            $carbon = static::parse($value, static::site());
            return $carbon ? $carbon->format($format) : $value;
        }
        $carbon = static::toUser($value);
        return $carbon ? $carbon->format($format ?? static::DB_FORMAT) : $value;
    }

    /**
     * A date (Y-m-d) of a date and time of the database in the time zone of the display;
     * a date as it is (a date is never converted).
     *
     * @param mixed $value
     * @param bool $isDatetime whether the value is a date and time (else only a date)
     */
    public static function displayDate($value, bool $isDatetime = true): ?string
    {
        if (is_nullorempty($value)) {
            return null;
        }
        if ($isDatetime && static::differs()) {
            $carbon = static::toUser($value);
            if ($carbon) {
                return $carbon->format('Y-m-d');
            }
        }
        $date = substr(($value instanceof \DateTimeInterface) ? $value->format('Y-m-d') : strval($value), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }

    /**
     * The label of a date and time grouped by the database (a summary view, a chart): the groups are
     * the days, months ... of the site, so the time zone of the site is shown when the user's differs.
     *
     * @param mixed $label
     * @param mixed $column_item
     * @return mixed
     */
    public static function siteGroupLabel($label, $column_item)
    {
        if (!static::differs() || !static::isDatetimeItem($column_item)) {
            return $label;
        }
        return exmtrans('common.site_timezone_label', ['label' => $label, 'timezone' => static::site()]);
    }

    /**
     * Whether a column item holds a date and time (a column of a date and time, created_at etc.).
     *
     * @param mixed $column_item
     */
    public static function isDatetimeItem($column_item): bool
    {
        if (!is_object($column_item)) {
            return false;
        }
        if ($column_item instanceof \Exceedone\Exment\ColumnItems\SystemItem) {
            return $column_item->isSystemDatetime();
        }
        return method_exists($column_item, 'isDateTime') && boolval($column_item->isDateTime());
    }

    /**
     * Format a date and time the user typed for the database. The value as it is when nothing converts.
     *
     * @param mixed $value
     * @param string $format
     * @return mixed
     */
    public static function formatFromUser($value, string $format = self::DB_FORMAT)
    {
        if (is_nullorempty($value) || is_array($value) || !static::differs()) {
            return $value;
        }
        $carbon = static::fromUser($value);
        return $carbon ? $carbon->format($format) : $value;
    }

    /**
     * Now, as a date and time of the display (in the time zone of the display, without a time zone:
     * for a default value of a form).
     */
    public static function nowString(string $format = self::DB_FORMAT): string
    {
        return Carbon::now(static::user())->format($format);
    }

    /**
     * Today of the user, as a date of the site (00:00 of that day in the time zone of the site, so
     * that it compares and formats with the dates of the database as before).
     */
    public static function today(): Carbon
    {
        if (!static::differs()) {
            return Carbon::today();
        }
        return Carbon::parse(Carbon::now(static::user())->format('Y-m-d'), static::site());
    }

    /**
     * The first day of this / last / next month or year of the user (as today()).
     */
    public static function relative(string $modifier): Carbon
    {
        if (!static::differs()) {
            return new Carbon($modifier);
        }
        $local = new Carbon($modifier, static::user());
        return Carbon::parse($local->format('Y-m-d'), static::site());
    }

    /**
     * The start of a day of the user (a date) for a query of a column of a date and time:
     * "Y-m-d" as before when nothing converts, else 00:00 of that day of the user in the time of the site.
     *
     * @param Carbon|string $day
     */
    public static function dayStartForQuery($day): string
    {
        $date = ($day instanceof \DateTimeInterface) ? $day->format('Y-m-d') : Carbon::parse($day)->format('Y-m-d');
        if (!static::differs()) {
            return $date;
        }
        return Carbon::parse($date, static::user())->setTimezone(static::site())->format(static::DB_FORMAT);
    }

    /**
     * A date and time of the database as a date and time of the display without the time zone
     * (to compare with today() etc. in the conditions of the memory). The value as it is when nothing converts.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function localString($value)
    {
        if (is_nullorempty($value) || !static::differs()) {
            return $value;
        }
        try {
            $carbon = static::toUser($value);
        } catch (\Throwable $ex) {
            return $value;
        }
        return $carbon ? $carbon->format(static::DB_FORMAT) : $value;
    }

    // import / export ----------------------------------------------------

    /**
     * Whether the import / export now reads and writes the date and times in the time zone of the user.
     */
    public static function dataAsUser(): bool
    {
        return static::$dataMode === 'user' && static::differs();
    }

    /**
     * Run an import / export: in the time zone of the user ($asUser) or of the site (default).
     *
     * @param bool $asUser
     * @param \Closure $callback
     * @return mixed
     */
    public static function runData(bool $asUser, \Closure $callback)
    {
        $before = static::$dataMode;
        // the texts of an export (view export) follow the choice too (formatToUser);
        // the conditions of the view ("today" etc.) stay the days of the user
        static::$dataMode = $asUser ? 'user' : 'site';
        try {
            return $callback();
        } finally {
            static::$dataMode = $before;
        }
    }

    // ----------------------------------------------------

    /**
     * @param mixed $value
     * @param string $timezone the time zone of a value without a time zone
     */
    protected static function parse($value, string $timezone): ?Carbon
    {
        if (is_nullorempty($value)) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            // a Carbon of the database has the time zone of the site already
            return Carbon::instance($value)->copy();
        }
        if (is_array($value)) {
            $value = array_get($value, 'date');
        }
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        try {
            return Carbon::parse((string)$value, $timezone);
        } catch (\Throwable $ex) {
            return null;
        }
    }

    protected static function offsetsDiffer(string $a, string $b): bool
    {
        if ($a === $b) {
            return false;
        }
        try {
            return (new \DateTimeZone($a))->getName() !== (new \DateTimeZone($b))->getName();
        } catch (\Throwable $ex) {
            return false;
        }
    }
}
