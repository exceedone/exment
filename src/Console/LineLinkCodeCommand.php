<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Services\Line\LineAccountLinker;
use Illuminate\Console\Command;

class LineLinkCodeCommand extends Command
{
    protected $signature = 'exment:line-linkcode {userId : id Exment user cần liên kết}';
    protected $description = '(Fallback) Sinh mã liên kết LINE + deep link cho 1 Exment user. Luồng chính là trang LINE連携.';

    public function handle(): int
    {
        $userId = (int) $this->argument('userId');

        $linker = new LineAccountLinker();
        $code   = $linker->generateCodeForUser($userId);
        $link   = $linker->deepLink($code);

        $this->info("Mã liên kết: {$code}");
        $this->info('Deep link: ' . $link);
        $this->comment('Mở deep link trên điện thoại đã add OA, hoặc dùng trang 「LINE連携」 để hiển thị QR.');

        return 0;
    }
}
