<?php

namespace Exceedone\Exment\Model\Traits;

/**
 * @used-by \Exceedone\Exment\Services\ClassBuilder
 */
trait DocumentTrait
{
    public function getUrlAttribute()
    {
        return $this->getUrl();
    }
    public function getTagUrlAttribute()
    {
        return $this->getUrl(true);
    }
    public function getApiUrlAttribute()
    {
        return $this->getUrl([
            'asApi' => true,
        ]);
    }
    public function getFileUuidAttribute()
    {
        return $this->getValue('file_uuid', true);
    }
}
