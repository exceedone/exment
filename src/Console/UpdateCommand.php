<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Services\TemplateImportExport\TemplateImporter;

class UpdateCommand extends Command
{
    use CommandTrait, InstallUpdateTrait;

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
     * @return void
     */
    public function handle()
    {
        $this->publishStaticFiles();
        
        $this->createBootstrapFile();
        
        $this->initDatabase();
    }

    /**
     * Create tables and seed it.
     *
     * @return void
     */
    public function initDatabase()
    {
        if (boolval(config('exment.use_cache', false))) {
            $this->call('cache:clear');
        }
        $this->call('migrate');

        // Remove template import if update
        // $importer = new TemplateImporter;
        // $importer->importSystemTemplate(true);
    }
}
