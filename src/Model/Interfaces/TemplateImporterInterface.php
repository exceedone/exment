<?php

namespace Exceedone\Exment\Model\Interfaces;

interface TemplateImporterInterface
{
    public static function importTemplate($json, $flg, $options = []);
}
