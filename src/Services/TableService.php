<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Form\Tools\SwalInputButton;
use Exceedone\Exment\Form\Tools\ModalButton;
use Exceedone\Exment\Form\Tools\ShareButton;


class TableService
{
    public static function appendActivateSwalButtonQRCode($tools, $custom_table)
    {
        if (!$custom_table->getOption('active_qr_flg')) {
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
                'text' => exmtrans('custom_table.help.qrcode_deactivate'),
                'method' => 'post',
                'redirectUrl' => admin_urls("table", $custom_table->id, "edit?qrcodesetting=1"),
            ]));
        }
    }

    public static function appendCreateAndDownloadButtonQRCode($tools, $custom_table)
    {
        if ($custom_table->getOption('active_qr_flg')) {
            $tools->append(new SwalInputButton([
                'url' => route('exment.qrcode_download', ['tableKey' => $custom_table->id]),
                'label' => exmtrans("custom_table.qr_code.download"),
                'icon' => 'fa-arrow-circle-down',
                'btn_class' => 'btn-success download-qr',
                'title' => exmtrans("common.download"),
                'text' => exmtrans('common.message.confirm_execute', exmtrans('common.download')),
                'method' => 'post'
            ]));
            $tools->append(new ModalButton([
                'url' => route('exment.form_create_qrcode', ['tableKey' => $custom_table->id]),
                'label' => exmtrans("custom_table.qr_code.create"),
                'icon' => 'fa-qrcode',
                'btn_class' => 'btn-success create-qr',
            ]));
        }
    }
}
