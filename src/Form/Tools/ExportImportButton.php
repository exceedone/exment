<?php

namespace Exceedone\Exment\Form\Tools;

use Encore\Admin\Grid;

/**
 * Data export and import button
 */
class ExportImportButton extends \Encore\Admin\Grid\Tools\ExportButton
{
    protected $table_name;

    public function __construct($table_name, Grid $grid)
    {
        $this->table_name = $table_name;
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
        $import_template = admin_base_path('data/'.$this->table_name).'?_export_=all&temp=1'; // laravel-admin 1.6.1
        $import_template_trans = exmtrans('custom_value.template');

        $import_export = exmtrans('custom_value.import_export');

        $page = request('page', 1);

        // get format and list array
        $buttons = [];
        // output formats
        $formats = [
            'csv'  => 'CSV',
            'excel' => 'Excel',
        ];

        foreach ($formats as $format => $format_text) {
            $buttons[$format] = [
                'format_text' => $format_text,
                'menulist' => [
                    ///// export
                    [
                        'action' => 'export',
                        'label' => trans('admin.export'),
                        'items' =>[
                            ['href' => $this->grid->getExportUrl('all'), 'text' => $all, 'target' => '_blank'],
                            ['href' => $this->grid->getExportUrl('page', $page), 'text' => $currentPage, 'target' => '_blank'],
                            ['href' => $this->grid->getExportUrl('selected', '__rows__'), 'text' => $selectedRows, 'class' => $this->grid->getExportSelectedName(), 'target' => '_blank'],
                        ]
                    ],
                    ///// import
                    [
                        'action' => 'import',
                        'label' => exmtrans('common.import'),
                        'items' =>[
                            ['href' => $import_template, 'text' => $import_template_trans, 'target' => '_blank'],
                            ['href' => 'javascript:void(0);', 'text' => $import, 'data-toggle' => 'modal', 'data-target' => '#data_import_modal', 'format_query' => false],
                        ]
                    ],
                ]
            ];
        }

        return view('exment::tools.exportimport-button', [
            'buttons' => $buttons,
        ]);
    }
}
