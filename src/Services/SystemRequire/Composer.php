<?php
namespace Exceedone\Exment\Services\SystemRequire;

use Exceedone\Exment\Enums\SystemRequireResult;

class Composer extends SystemRequireBase
{
    protected $version;

    public function __construct()
    {
        // check backup execute
        try {
            // this command is too slow.
            //$command = 'composer --version';
            $command = \Exment::isWindows() ? 'where composer' : 'which composer';
            
            foreach(['', '.phar'] as $suffix){
                exec($command . $suffix, $output, $return_var);
                if ($return_var == 0) {
                    $this->result = SystemRequireResult::OK;
                    return;
                }
            }

            $this->result = SystemRequireResult::WARNING;
        } catch (\Exception $ex) {
            $this->result = SystemRequireResult::WARNING;
        }
    }

    public function getLabel() : string
    {
        return exmtrans('system_require.type.composer.label');
    }

    public function getExplain() : string
    {
        return exmtrans('system_require.type.composer.explain');
    }


    /**
     * Undocumented function
     *
     * @return ?string
     */
    public function getResultText() : ?string
    {
        switch ($this->result) {
            case SystemRequireResult::OK:
                return exmtrans('common.success');
                
            case SystemRequireResult::WARNING:
                return exmtrans('common.warning');
                
            case SystemRequireResult::NG:
                return exmtrans('common.error');
        }
    }

    /**
     *
     *
     * @return string
     */
    public function checkResult() : string
    {
        return $this->result;
    }

    protected function getMessageWarning() : ?string
    {
        return exmtrans('system_require.type.composer.warning');
    }

    public function getSettingUrl() : ?string
    {
        return \Exment::getManualUrl('quickstart');
    }
}
