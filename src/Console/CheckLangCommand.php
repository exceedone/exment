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
     * The missing keys of the language now checked.
     *
     * @var int
     */
    protected $missingCount = 0;

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
    public function handle(): int
    {
        // the keys of Exment's own ja file (not the texts an application adds over them, which
        // fall back to another language by design)
        $jaTrans = require exment_package_path('resources/lang/ja/exment.php');
        // all the installed languages (the folders of resources/lang), against ja
        $langs = SystemLocale::getLocaleOptions();

        $hasError = false;
        foreach ($langs as $lang => $label) {
            if ($lang == 'ja') {
                continue;
            }

            $this->missingCount = 0;
            $result = $this->checkTrans(['exment::exment'], $jaTrans, $lang);
            if ($result === true) {
                $hasError = true;
                $this->warn("{$lang}: {$this->missingCount} missing");
            } else {
                $this->info("{$lang}: OK");
            }
        }
        return $hasError ? 1 : 0;
    }

    /**
     * Check translation recursively
     *
     * @param array<int, string> $keys
     * @param mixed $jat
     * @param string $lang
     * @return bool
     */
    protected function checkTrans(array $keys, $jat, $lang)
    {
        $hasError = false;
        foreach ($jat as $key => $value) {
            $langKeys = $keys;
            $langKeys[] = $key;

            if (is_array($value)) {
                $result = $this->checkTrans($langKeys, $value, $lang);
                if ($result === true) {
                    $hasError = true;
                }
            } else {
                // only the file of that language (not the fallback language)
                if (!\Lang::hasForLocale(implode(".", $langKeys), $lang)) {
                    $langKey = implode(".", $langKeys);
                    $this->warn("{$lang} {$langKey}");
                    $this->missingCount++;
                    $hasError = true;
                }
            }
        }
        return $hasError;
    }
}
