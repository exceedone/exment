<?php

namespace Exceedone\Exment\Form\Navbar;

use Illuminate\Contracts\Support\Renderable;

/**
 * Item hidden input
 */
class Hidden implements Renderable
{
    public function render()
    {
        $values = array_merge([
            'gridrow_select_edit' => config('exment.gridrow_select_edit', 0),
            'gridrow_select_disabled' => config('exment.gridrow_select_disabled', 0),
            'copy_toastr' => exmtrans('common.message.copy_execute'),
            'exment_undefined_error' => exmtrans('error.undefined_error'),
            'exment_error_title' => exmtrans('common.error'),
            'exment_expired_error' => exmtrans('error.expired_error'),
            // has-many table validation (JS)
            'exment_hm_validation_title' => exmtrans('validation.hasmany_hidden_required_title'),
            'exment_hm_validation_plain_prefix' => exmtrans('validation.hasmany_hidden_required_plain_prefix'),
            'exment_hm_validation_html_prefix' => exmtrans('validation.hasmany_hidden_required_html_prefix'),
            'exment_hm_validation_ok' => exmtrans('validation.hasmany_hidden_required_ok'),
            'exment_common_row' => exmtrans('common.row'),
        ], static::getHiddenItemsCommon());

        $html = '';
        foreach ($values as $key => $value) {
            $html .= <<<HTML
            <input type="hidden" id="{$key}" value="{$value}" />
HTML;
        }

        return $html;
    }


    public static function getHiddenItemsCommon()
    {
        return [
            'admin_prefix' => config('admin.route.prefix') ?? '',
            'admin_base_uri' => trim(app('request')->getBaseUrl(), '/'),
            'admin_uri' => admin_url(),
        ];
    }
}
