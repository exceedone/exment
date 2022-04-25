<?php

namespace Exceedone\Exment\Services\DataImportExport;

use Encore\Admin\Widgets\Grid\Exporters\AbstractExporter;
/**
 * Data import export service for widget grid.
 */
class DataImportExportWidgetService extends AbstractExporter
{
    use DataImportExportServiceTrait;

    const SCOPE_ALL = 'all';
    const SCOPE_TEMPLATE = 'temp';
    const SCOPE_CURRENT_PAGE = 'page';
    const SCOPE_SELECTED_ROWS = 'selected';

}
