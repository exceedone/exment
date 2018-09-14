<?php

namespace Exceedone\Exment\ExmentImporters;


abstract class ExmentAbstractImporter implements ExmentImporterInterface
{
    /**
     * {@inheritdoc}
     */
    abstract public function import($request);
}
