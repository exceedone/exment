<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Services\RefreshDataService;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\SystemTableName;

/**
 * Refresh custom data.
 */
class RefreshTableDataCommand extends Command
{
    use CommandTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:refreshtable {table_name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh custom data selecting custom table.';

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
        /** @var null|string $table_names */
        $table_names = $this->argument("table_name");
        if ($table_names === null) {
            throw new \Exception('parameter table name is empty');
        }
        $table_names = stringToArray($table_names);

        // check table exists
        $notExistsTables = collect($table_names)->filter(function ($table_name) {
            return !CustomTable::getEloquent($table_name);
        });
        if ($notExistsTables->count() > 0) {
            $this->error('Table ' . $notExistsTables->implode(",") . " are not found.");
            return 1;
        }

        // if contains user org mailtemplate table, return
        $userOrgTables = collect($table_names)->filter(function ($table_name) {
            return isMatchString($table_name, SystemTableName::USER) || isMatchString($table_name, SystemTableName::ORGANIZATION) || isMatchString($table_name, SystemTableName::MAIL_TEMPLATE);
        });
        if ($userOrgTables->count() > 0) {
            $this->error('Table ' . $userOrgTables->implode(",") . " cannot refresh.");
            return 1;
        }

        if (!$this->confirm('Really refresh data? All refresh custom data.')) {
            return 1;
        }

        RefreshDataService::refreshTable($table_names);

        return 0;
    }
}
