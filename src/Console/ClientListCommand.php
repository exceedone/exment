<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Laravel\Passport\Client;

class ClientListCommand extends Command
{
    use CommandTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'passport:clientlist';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show clients for issuing access tokens';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->showClients();
        return 0;
    }

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
     * Show clients for issuing access tokens
     *
     * @return void
     */
    protected function showClients()
    {
        $clients = Client::all();

        foreach ($clients as $client) {
            $this->line('<comment>Name:</comment> '.$client->name);
            $this->line('<comment>Client Type:</comment> '.$this->getClientType($client));
            $this->line('<comment>User ID:</comment> '.$client->user_id);
            $this->line('<comment>Client ID:</comment> '.$client->id);
            $this->line('<comment>Client secret:</comment> '.$client->secret);
            $this->line('<comment>Redirect:</comment> '.$client->redirect);
            $this->line('');
        }
    }

    /**
     * get client type string
     */
    protected function getClientType(Client $client)
    {
        if ($client->personal_access_client == false && $client->password_client == true) {
            return 'Password Grant';
        }
        if ($client->personal_access_client == true && $client->password_client == false) {
            return 'Personal Access';
        }
        if ($client->personal_access_client == false && $client->password_client == false) {
            // check userid
            if (isset($client->user_id)) {
                return 'Auth Code';
            }
            return 'Client Credentials';
        }
        return null;
    }
}
