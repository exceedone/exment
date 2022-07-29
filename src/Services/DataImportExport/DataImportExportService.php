<?php

namespace Exceedone\Exment\Services\DataImportExport;

use Encore\Admin\Grid\Exporters\AbstractExporter;

class DataImportExportService extends AbstractExporter
{
    use DataImportExportServiceTrait;

    public const SCOPE_ALL = 'all';
    public const SCOPE_TEMPLATE = 'temp';
    public const SCOPE_CURRENT_PAGE = 'page';
    public const SCOPE_SELECTED_ROWS = 'selected';
}
