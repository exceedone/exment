<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Services\NotifyService;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class NotifyTestCommand extends Command
{
    use CommandTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:notifytest {--type=mail} {--to=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test for sending notify';

    /**
     * Create a new command instance.
     *
     * @return void
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
    public function handle()
    {
        try {
            // get parameter
            $send_type = $this->option("type") ?? 'mail';
            $to_address = $this->option("to");

            if (is_null($to_address)) {
                return -1;
            }

            NotifyService::executeTestNotify([
                'type' => $send_type,
                'to' => $to_address,
            ]);

            $this->line('Send mail Success.');
            return 0;
        }
        // throw mailsend Exception
        catch (TransportExceptionInterface  $ex) {
            $this->error('Send mail Error. Please check log.');
            \Log::error($ex);
        } catch (\Exception $ex) {
            return -1;
        } finally {
        }
        return 0;
    }
}
