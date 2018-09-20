<?php

namespace Exceedone\Exment\Form\Tools;

use Encore\Admin\Grid;

/**
 * Data export and import button
 */
class ExportImportButton extends \Encore\Admin\Grid\Tools\ExportButton
{
    protected $table_name;

    public function __construct($table_name, Grid $grid){
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

        return <<<EOT

<div class="btn-group pull-right" style="margin-right: 5px">
    <button type="button" class="btn btn-sm btn-twitter dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <i class="fa fa-download"></i> {$import_export}
        <span class="caret"></span>
    </button>
    <ul class="dropdown-menu">
        <li class="dropdown-header">$export</li>
        <li><a href="{$this->grid->getExportUrl('all')}" target="_blank">{$all}</a></li>
        <li><a href="{$this->grid->getExportUrl('page', $page)}" target="_blank">{$currentPage}</a></li>
        <li><a href="{$this->grid->getExportUrl('selected', '__rows__')}" target="_blank" class='{$this->grid->getExportSelectedName()}'>{$selectedRows}</a></li>
        <li class="dropdown-header">$import</li>
        <li><a href="$import_template" target="_blank">$import_template_trans</a></li>
        <li><a href="" data-toggle="modal" data-target="#data_import_modal" target="_blank">$import</a></li>
    </ul>
</div>
&nbsp;&nbsp;

EOT;
    }
}
