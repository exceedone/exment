<?php

namespace Exceedone\Exment\Model\Interfaces;

interface TemplateImporterInterface
{

    // @phpstan-ignore-next-line
    public static function importTemplate($json, $flg, $options = []);
}
