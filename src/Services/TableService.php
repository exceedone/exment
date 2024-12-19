<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Form\Tools\SwalInputButton;
use Exceedone\Exment\Form\Tools\ModalButton;
use Illuminate\Support\Facades\App;

class TableService
{
    public static function appendActivateSwalButtonQRCode($tools, $custom_table)
    {
        if (!$custom_table->getOption('jan_use')) {
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
    }

    public static function appendActivateSwalButtonJanCode($tools, $custom_table)
    {
        if (!$custom_table->getOption('qr_use')) {
            if (!$custom_table->getOption('active_qr_flg')) {
                if (!$custom_table->getOption('active_jan_flg')) {
                    $tools->append(new SwalInputButton([
                        'url' => route('exment.jancode_activate', ['id' => $custom_table->id]),
                        'label' => exmtrans('common.activate'),
                        'icon' => 'fa-check-circle',
                        'btn_class' => 'btn-success',
                        'title' => exmtrans('common.activate'),
                        'text' => exmtrans('custom_table.help.jancode_activate'),
                        'method' => 'post',
                        'redirectUrl' => admin_urls("table", $custom_table->id, "edit?jancodesetting=1"),
                    ]));
                } else {
                    $tools->append(new SwalInputButton([
                        'url' => route('exment.jancode_deactivate', ['id' => $custom_table->id]),
                        'label' => exmtrans('common.deactivate'),
                        'icon' => 'fa-check-circle',
                        'btn_class' => 'btn-default',
                        'title' => exmtrans('common.deactivate'),
                        'html' => exmtrans('custom_table.help.jancode_deactivate'),
                        'method' => 'post',
                        'redirectUrl' => admin_urls("table", $custom_table->id, "edit?jancodesetting=1"),
                    ]));
                }
            } else {
                $custom_table->setOption('qr_use', true);
                $custom_table->save();
            }
        }
    }

    public static function appendCreateAndDownloadButtonQRCode($tools, $custom_table)
    {
        $current_locale = App::getLocale();
        $text_button = config("exment.text_qr_button_{$current_locale}");
        $label_create = exmtrans("custom_table.qr_code.create", $text_button ?? exmtrans('dashboard_box_system_pages.barcode'));
        $label_download = exmtrans("custom_table.qr_code.download", $text_button ?? exmtrans('dashboard_box_system_pages.barcode'));

        if ($custom_table->getOption('active_qr_flg')) {
            $tools->append(new SwalInputButton([
                'url' => route('exment.qrcode_download', ['tableKey' => $custom_table->id]),
                'label' => $label_download,
                'icon' => 'fa-arrow-circle-down',
                'btn_class' => 'btn-success download-qr',
                'title' => exmtrans("common.download"),
                'text' => exmtrans('common.message.confirm_execute', exmtrans('common.download')),
                'method' => 'post'
            ]));
            $tools->append(new ModalButton([
                'url' => route('exment.form_create_qrcode', ['tableKey' => $custom_table->id]),
                'label' => $label_create,
                'icon' => 'fa-qrcode',
                'btn_class' => 'btn-success create-qr',
            ]));
        }
    }
}
