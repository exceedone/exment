<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Services\SystemRequire\SystemRequireList;
use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Enums\SystemRequireCalledType;

/**
 * The tuning report of Admin Setting > Tuning, on the command line (ISS02823).
 * For the server administrator, also where the page is kept from the administrators of the
 * sites (a SaaS: EXMENT_FEATURES_LOCKED=tuning). Only reads; it changes nothing.
 */
class TuningCommand extends Command
{
    use CommandTrait;

    /**
     * @var string
     */
    protected $signature = 'exment:tuning {--only-warning : Show only the items that are not set}';

    /**
     * @var string
     */
    protected $description = 'Show the tuning report (what would make this site faster). Changes nothing.';

    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->initExmentCommand();
    }

    /**
     * @return int
     */
    public function handle()
    {
        $this->line(exmtrans('tuning.command_header'));
        // OPcache and the like are the ones of the PHP of this command, not of the site
        $this->line(exmtrans('tuning.command_note'));
        $this->line('---------------------------');

        $warnings = 0;
        foreach (SystemRequireList::make(SystemRequireCalledType::TUNING)->getItems() as $check) {
            $result = $check->checkResult();
            if ($result != SystemRequireResult::OK) {
                $warnings++;
            } elseif (boolval($this->option('only-warning'))) {
                continue;
            }

            $this->line(exmtrans('system_require.item_header', ['label' => $check->getLabel(), 'text' => $check->getResultText(), 'result' => strtoupper($result)]));
            if ($result != SystemRequireResult::OK) {
                $this->comment((string) $check->getMessage());
                if (!is_null($fix = $check->getFixText())) {
                    $this->line($fix);
                }
            }
            $this->line('---------------------------');
        }

        $this->info(exmtrans('tuning.command_summary', ['count' => $warnings]));
        return 0;
    }
}
