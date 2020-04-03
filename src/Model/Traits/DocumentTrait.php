<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Services\AuthUserOrgHelper;

trait DocumentTrait
{
    public function getUrlAttribute(){
        return $this->getUrl();
    }
    public function getTagUrlAttribute(){
        return $this->getUrl(true);
    }
    public function getApiUrlAttribute(){
        return $this->getUrl([
            'asApi' => true,
        ]);
    } 
    public function getFileUuidAttribute(){
        return $this->getValue('file_uuid', true);
    } 
}
