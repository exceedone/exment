<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Services\TemplateImportExport\TemplateImporter;

class UpdateCommand extends Command
{
    use CommandTrait;
    use InstallUpdateTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the exment package';

    /**
     * Install directory.
     *
     * @var string
     */
    protected $directory = '';

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
        $this->publishStaticFiles();

        $this->createExmentBootstrapFile();

        $this->initDatabase();

        return 0;
    }

    /**
     * Create tables and seed it.
     *
     * @return void
     */
    public function initDatabase()
    {
        foreach (['cache:clear', 'config:clear', 'route:clear', 'view:clear'] as $command) {
            try {
                $this->call($command);
            } catch (\Exception $ex) {
            }
        }

        $this->call('migrate');

        // Remove template import if update
        // $importer = new TemplateImporter;
        // $importer->importSystemTemplate(true);
    }
}
