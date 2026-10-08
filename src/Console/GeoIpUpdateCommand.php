<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Services\GeoIp\GeoIpService;

/**
 * Download the GeoIP database, used for resolving country and location of login history.
 * Usage:
 *   php artisan exment:geoip-update              -- download from config "exment.geoip_download_url"
 *   php artisan exment:geoip-update --url=https://example.com/geoip.mmdb.gz  -- download from the url
 */
class GeoIpUpdateCommand extends Command
{
    use CommandTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:geoip-update
                            {--url= : Download url of the database file (.mmdb or .mmdb.gz)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download GeoIP database file and replace the current one';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
        $this->initExmentCommand();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        try {
            $path = GeoIpService::download($this->option('url'));
        } catch (\Throwable $ex) {
            \Log::error($ex);
            $this->error($ex->getMessage());
            return 1;
        }

        $info = GeoIpService::getDatabaseInfo();
        $this->info("GeoIP database updated : {$path}" . (is_null($info) ? '' : " ({$info['type']} {$info['build_date']->format('Y-m-d')})"));
        return 0;
    }
}
