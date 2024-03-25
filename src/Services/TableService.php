<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Form\Tools\SwalInputButton;

class TableService
{
    public static function appendActivateSwalButtonQRCode($tools, $custom_table)
    {
        if (!$custom_table->active_flg) {
            $tools->append(new SwalInputButton([
                'url' => route('exment.qrcode_activate', ['id' => $custom_table->id]),
                'label' => exmtrans('common.activate'),
                'icon' => 'fa-check-circle',
                'btn_class' => 'btn-success',
                'title' => exmtrans('common.activate'),
                'text' => exmtrans('custom_table.help.qrcode_activate'),
                'method' => 'post',
                'redirectUrl' => admin_urls("table", $custom_table->id, "edit?qrcodesetting=1"),
            ]));
        } else {
            $tools->append(new SwalInputButton([
                'url' => route('exment.qrcode_deactivate', ['id' => $custom_table->id]),
                'label' => exmtrans('common.deactivate'),
                'icon' => 'fa-check-circle',
                'btn_class' => 'btn-default',
                'title' => exmtrans('common.deactivate'),
                'text' => exmtrans('custom_table.help.qrcode_activate'),
                'method' => 'post',
                'redirectUrl' => admin_urls("table", $custom_table->id, "edit?qrcodesetting=1"),
            ]));
        }
    }
}
