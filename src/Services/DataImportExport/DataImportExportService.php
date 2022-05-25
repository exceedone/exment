<?php

namespace Exceedone\Exment\Services\DataImportExport;

use Encore\Admin\Grid\Exporters\AbstractExporter;

class DataImportExportService extends AbstractExporter
{
    use DataImportExportServiceTrait;

    const SCOPE_ALL = 'all';
    const SCOPE_TEMPLATE = 'temp';
    const SCOPE_CURRENT_PAGE = 'page';
    const SCOPE_SELECTED_ROWS = 'selected';
}
