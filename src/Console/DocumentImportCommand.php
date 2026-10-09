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

    // @phpstan-ignore-next-line
    protected static $actionClassName = DataImportExport\Actions\Import\DocumentAction::class;

    // @phpstan-ignore-next-line
    protected static $directoryName = 'document-import';

    // @phpstan-ignore-next-line
    protected static $files_name = 'documents';
}
