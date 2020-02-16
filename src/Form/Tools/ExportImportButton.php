<?php

namespace Exceedone\Exment\Form\Tools;

use Encore\Admin\Grid;

/**
 * Data export and import button
 */
class ExportImportButton extends \Encore\Admin\Grid\Tools\ExportButton
{
    protected $endpoint;
    protected $export_flg;
    protected $import_flg;

    public function __construct($endpoint, Grid $grid, $export_flg = true, $import_flg = true)
    {
        $this->endpoint = $endpoint;
        $this->export_flg = $export_flg;
        $this->import_flg = $import_flg;
        parent::__construct($grid);
    }

    /**
     * Render Export button.
     *
     * @return string
     */
    public function render()
    {
        // if (!$this->grid->allowExport()) {
        //     return '';
        // }

        $this->setUpScripts();

        $export = trans('admin.export');
        $all = trans('admin.all');
        $currentPage = trans('admin.current_page');
        $selectedRows = trans('admin.selected_rows');
        
        // import
        $import = exmtrans('common.import');
        $import_template = $this->endpoint.'?_export_=all&temp=1'; // laravel-admin 1.6.1
        $import_template_trans = exmtrans('custom_value.template');

        // switch label
        if ($this->export_flg && $this->import_flg) {
            $label = exmtrans('custom_value.import_export');
        } elseif ($this->export_flg) {
            $label = exmtrans('custom_value.export');
        } elseif ($this->import_flg) {
            $label = exmtrans('custom_value.import_label');
        }

        $page = request('page', 1);

        // get format and list array
        $buttons = [];
        
        // output formats
        $formats = [];
        // check config value
        if (!boolval(config('exment.export_import_export_disabled_csv', false))) {
            $formats['csv'] = 'CSV';
        }
        if (!boolval(config('exment.export_import_export_disabled_excel', false))) {
            $formats['excel'] = 'Excel';
        }

        foreach ($formats as $format => $format_text) {
            $items = [
                ['href' => $this->grid->getExportUrl('all'), 'text' => $all, 'target' => '_blank'],
                ['href' => $this->grid->getExportUrl('page', $page), 'text' => $currentPage, 'target' => '_blank'],
            ];
            if (!$this->grid->disableRowSelector()) {
                $items[] = ['href' => $this->grid->getExportUrl('selected', '__rows__'), 'text' => $selectedRows, 'class' => $this->grid->getExportSelectedName(), 'target' => '_blank'];
            }

            $menulist = [];
            if ($this->export_flg) {
                ///// export
                $menulist[] = [
                    'action' => 'export',
                    'label' => trans('admin.export'),
                    'items' => $items
                ];
            }

            if ($this->import_flg) {
                ///// import
                $menulist[] = [
                    'action' => 'import',
                    'label' => exmtrans('common.import'),
                    'items' =>[
                        ['href' => $import_template, 'text' => $import_template_trans, 'target' => '_blank'],
                        ['href' => 'javascript:void(0);', 'text' => $import,  'data-widgetmodal_url' => url_join($this->endpoint, 'importModal'), 'format_query' => false],
                    ]
                ];
            }

            $buttons[$format] = [
                'format_text' => $format_text,
                'menulist' => $menulist
            ];
        }

        return view('exment::tools.exportimport-button', [
            'buttons' => $buttons,
            'button_caption' => $label,
        ]);
    }
}
