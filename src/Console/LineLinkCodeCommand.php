<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Services\Line\LineAccountLinker;
use Illuminate\Console\Command;

class LineLinkCodeCommand extends Command
{
    protected $signature = 'exment:line-linkcode {userId : Exment user id to link}';
    protected $description = '(Fallback) Generate a LINE link code + deep link for an Exment user. The main flow is the LINE連携 page.';

    public function handle(): int
    {
        $userId = (int) $this->argument('userId');

        $linker = new LineAccountLinker();
        $code   = $linker->generateCodeForUser($userId);
        $link   = $linker->deepLink($code);

        $this->info(exmtrans('line.linkcode_code', $code));
        $this->info('Deep link: ' . $link);
        $this->comment(exmtrans('line.linkcode_hint'));

        return 0;
    }
}
