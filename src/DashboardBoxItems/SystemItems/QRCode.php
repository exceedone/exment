<?php

namespace Exceedone\Exment\DashboardBoxItems\SystemItems;

use Illuminate\Support\Facades\App;

class QRCode
{
    /**
     * get header
     */
    public function header()
    {
        return null;
    }

    /**
     * get footer
     */
    public function footer()
    {
        return null;
    }

    /**
     * get html body
     */
    public function body()
    {
        $current_locale = App::getLocale();
        $text_button = config("exment.text_qr_button_{$current_locale}") ?? null;
        $label = exmtrans("custom_table.qr_code.reading", $text_button ?? exmtrans('dashboard_box_system_pages.qr_code'));

        return view('exment::dashboard.system.qrcode', [
            'label' => $label,
        ])->render() ?? null;
    }
}
