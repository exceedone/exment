<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Services\SystemRequire\SystemRequireList;
use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Enums\SystemRequireCalledType;

class CheckRequireCommand extends Command
{
    use CommandTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:checkrequire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check Exment require environment';

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
        $checkResult = SystemRequireList::make(SystemRequireCalledType::COMMAND);
        foreach ($checkResult->getItems() as $check) {
            $result = $check->checkResult();
            $funcName = $this->getCommandFuncName($result);

            $this->line(exmtrans('system_require.item_header', ['label' => $check->getLabel(), 'text' => $check->getResultText(), 'result' => strtoupper($result)]));

            if (!is_null($message = $check->getMessage())) {
                $this->{$funcName}($message);
                $this->{$funcName}($check->getSettingUrl());
            }

            $this->line('---------------------------');
        }
        return 0;
    }

    /**
     * Get func name from result
     *
     * @param string $result
     * @return string
     */
    protected function getCommandFuncName($result): string
    {
        switch ($result) {
            case SystemRequireResult::OK:
                return 'line';
            case SystemRequireResult::WARNING:
            case SystemRequireResult::CANNOT_CHECK:
                return 'comment';
            case SystemRequireResult::NG:
                return 'error';
        }
        return '';
    }
}
