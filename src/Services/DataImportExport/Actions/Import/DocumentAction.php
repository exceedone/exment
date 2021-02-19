<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Import;

use Exceedone\Exment\Services\DataImportExport\Providers\Import;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\RelationType;

/**
 * Document import action
 */
class DocumentAction extends FileColumnAction
{
    protected static $files_name = 'documents';
    
    /**
     * get provider
     */
    public function getProvider($table_name)
    {
        return new Import\DocumentProvider([
            'custom_table' => $this->custom_table,
            'fileDirFullPath' => $this->fileDirFullPath,
        ]);
    }
}
