<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Enums\SystemLocale;

class CheckLangCommand extends Command
{
    use CommandTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:checklang';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'get forgeting translate language file';

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
        $jaTrans = trans('exment::exment', [], 'ja');
        $langs = SystemLocale::getLocaleOptions();

        foreach ($langs as $lang => $label) {
            if ($lang == 'ja') {
                continue;
            }

            $this->checkTrans(['exment::exment'], $jaTrans, $lang);
        }
        return 0;
    }
    
    protected function checkTrans(array $keys, $jat, $lang)
    {
        foreach ($jat as $key => $value) {
            $langKeys = $keys;
            $langKeys[] = $key;

            if (is_array($value)) {
                $this->checkTrans($langKeys, $value, $lang);
            } else {
                if (!\Lang::has(implode(".", $langKeys), $lang)) {
                    $langKey = implode(".", $langKeys);
                    $this->info("{$lang} {$langKey}");
                }
            }
        }
    }
}
