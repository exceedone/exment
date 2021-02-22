<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Services\DataImportExport;

class DocumentImportCommand extends FileColumnImportCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:document-import {dir}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Document Import Exment data';

    protected static $actionClassName = DataImportExport\Actions\Import\DocumentAction::class;

    protected static $directoryName = 'document-import';

    protected static $files_name = 'documents';
}
