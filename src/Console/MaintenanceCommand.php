<?php

namespace Exceedone\Exment\Console;

use Carbon\Carbon;
use Exceedone\Exment\Services\MaintenanceMode;
use Illuminate\Console\Command;

/**
 * Maintenance mode from the server (ISS01396).
 *
 *   exment:maintenance status
 *   exment:maintenance on  [--until=] [--message=] [--allow-ip=*] [--api=admin|open|stop] [--keep-public] [--keep-schedule] [--keep-queue]
 *   exment:maintenance off
 *   exment:maintenance on --operator [--until=] [--message=] [--allow-ip=*] [--allow-admins]
 *   exment:maintenance off --operator
 *   exment:maintenance tick   (every minute by the schedule: the planned start and end)
 *
 * --operator is the maintenance of the server's operator (a SaaS host, an
 * update): a file that the screens of the site cannot change.
 * Not `php artisan down`, which is kept for the update from the screen.
 */
class MaintenanceCommand extends Command
{
    use CommandTrait;

    /**
     * @var string
     */
    protected $signature = 'exment:maintenance
        {action : status, on, off or tick}
        {--operator : the operator\'s maintenance (a file the site cannot change)}
        {--until= : the planned end (a date and time)}
        {--message= : the message on the maintenance page}
        {--allow-ip=* : an IP address or a range that gets in}
        {--allow-admins : (with --operator) the system administrators of the site get in}
        {--api= : the API: admin (system administrators only, the default), open or stop}
        {--keep-public : keep the public forms and share links}
        {--keep-schedule : keep the scheduled commands}
        {--keep-queue : keep the queue}';

    /**
     * @var string
     */
    protected $description = 'Start or end the maintenance mode, or show it';

    /**
     * @return int
     */
    public function handle()
    {
        $this->initExmentCommand();
        $action = strval($this->argument('action'));
        try {
            switch ($action) {
                case 'status':
                    return $this->status();
                case 'on':
                    return $this->on();
                case 'off':
                    return $this->off();
                case 'tick':
                    MaintenanceMode::sync();
                    MaintenanceMode::reset();
                    MaintenanceMode::runMissedSchedule();
                    return 0;
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }
        $this->error('The action is status, on, off or tick.');
        return 1;
    }

    protected function status(): int
    {
        $state = MaintenanceMode::current();
        $this->line(json_encode([
            'in_maintenance' => isset($state),
            'source' => $state['source'] ?? null,
            'operator' => MaintenanceMode::operator(),
            'site' => MaintenanceMode::settings(),
            'feature_used' => \Exceedone\Exment\Services\Features::enabled(MaintenanceMode::FEATURE),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        return 0;
    }

    protected function on(): int
    {
        $options = [];
        $until = $this->option('until');
        if (is_string($until) && $until !== '') {
            $end = Carbon::parse($until);
            if ($end->lte(Carbon::now())) {
                $this->error('--until has passed.');
                return 1;
            }
            $options['end_at'] = $end->toIso8601String();
        }
        $message = $this->option('message');
        if (is_string($message) && $message !== '') {
            $options['messages'] = [app()->getLocale() => $message];
        }
        $ips = array_filter((array) $this->option('allow-ip'));
        foreach ($ips as $ip) {
            if (!MaintenanceMode::validIp(strval($ip))) {
                $this->error("Not an IP address or a range: {$ip}");
                return 1;
            }
        }
        $options['allow_ips'] = array_values($ips);

        if ($this->option('operator')) {
            $options['allow_admins'] = boolval($this->option('allow-admins'));
            MaintenanceMode::startOperator($options);
            $this->info('The operator\'s maintenance started: ' . MaintenanceMode::operatorFile());
            return 0;
        }

        if (!\Exceedone\Exment\Services\Features::enabled(MaintenanceMode::FEATURE)) {
            $this->error('The feature "maintenance_mode" is not used. Turn it on in Admin setting > Feature management, or use --operator.');
            return 1;
        }
        $api = $this->option('api');
        if (is_string($api) && $api !== '') {
            if (!in_array($api, [MaintenanceMode::API_ADMIN, MaintenanceMode::API_OPEN, MaintenanceMode::API_STOP], true)) {
                $this->error('--api is admin, open or stop.');
                return 1;
            }
            $options['api'] = $api;
        }
        $options['stop_public'] = !$this->option('keep-public');
        $options['stop_schedule'] = !$this->option('keep-schedule');
        $options['stop_queue'] = !$this->option('keep-queue');
        MaintenanceMode::start($options);
        $this->info('The maintenance started.');
        return 0;
    }

    protected function off(): int
    {
        if ($this->option('operator')) {
            MaintenanceMode::endOperator();
            $this->info('The operator\'s maintenance ended.');
            return 0;
        }
        MaintenanceMode::end();
        $this->info('The maintenance ended.');
        return 0;
    }
}
