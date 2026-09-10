<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\LineAccountLink;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Services\Line\LineAccountLinker;
use Exceedone\Exment\Services\Line\QrRenderer;
use Illuminate\Http\Request;

class LineLinkController extends AdminControllerBase
{
    public function index(Request $request, Content $content)
    {
        $userId = (int) \Exment::user()->getUserId();
        $link   = LineAccountLink::forUser($userId);

        $qr = null;
        if (!$link->isLinked() && $link->hasActiveCode()) {
            $deepLink = (new LineAccountLinker())->deepLink($link->line_link_code);
            $qr = QrRenderer::svgDataUri($deepLink);
        }

        return $this->AdminContent($content)
            ->title(exmtrans('line.link_menu_title'))
            ->body(view('exment::line.link', ['link' => $link, 'qr' => $qr]));
    }

    public function generate(Request $request)
    {
        $userId = (int) \Exment::user()->getUserId();
        if (LineAccountLink::forUser($userId)->isLinked()) {
            // generateCode() clears line_user_id: a stale tab still showing the
            // "generate" form must not silently unlink an account linked meanwhile.
            admin_toastr(exmtrans('line.link_generate_blocked'), 'error');
            return redirect(admin_url('line/link'));
        }
        (new LineAccountLinker())->generateCodeForUser($userId);
        return redirect(admin_url('line/link'));
    }

    public function unlink(Request $request)
    {
        $userId = (int) \Exment::user()->getUserId();
        LineAccountLink::forUser($userId)->unlink();
        return redirect(admin_url('line/link'));
    }
}
