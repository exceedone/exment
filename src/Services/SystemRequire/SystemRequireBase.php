<?php

namespace Exceedone\Exment\Services\SystemRequire;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * System require check base
 */
abstract class SystemRequireBase
{
    /**
     * Getted require result
     *
     * @var mixed
     */
    protected $result;

    /**
     * Called type
     *
     * @var string
     */
    protected $systemRequireCalledType;


    public function systemRequireCalledType(string $systemRequireCalledType)
    {
        $this->systemRequireCalledType = $systemRequireCalledType;
        return $this;
    }


    public function getResult()
    {
        return $this->result;
    }


    /**
     * Get message text
     *
     * @return string
     */
    public function getMessage(): ?string
    {
        $checkResult = $this->checkResult();
        switch ($checkResult) {
            case SystemRequireResult::OK:
                return $this->getMessageOk();
            case SystemRequireResult::WARNING:
                return $this->getMessageWarning();
            case SystemRequireResult::NG:
                return $this->getMessageNg();
        }

        return null;
    }

    public function getResultClassSet(): ?array
    {
        switch ($this->checkResult()) {
            case SystemRequireResult::OK:
                return [
                    'fontawesome' => 'fa-check',
                    'color' => '#419641',
                ];
            case SystemRequireResult::WARNING:
                return [
                    'fontawesome' => 'fa-exclamation-triangle',
                    'color' => '#f0ad4e',
                ];
            case SystemRequireResult::NG:
                return [
                    'fontawesome' => 'fa-close',
                    'color' => '#d9534f',
                ];
        }
        return null;
    }


    protected function getMessageOk(): ?string
    {
        return null;
    }
    protected function getMessageWarning(): ?string
    {
        return null;
    }
    protected function getMessageNg(): ?string
    {
        return null;
    }

    abstract public function checkResult(): string;
    abstract public function getSettingUrl(): ?string;
    abstract public function getExplain(): string;

    /**
     * Get result text for display.
     *
     * @return string
     */
    abstract protected function getResultText(): ?string;

    /**
     * Get label
     *
     * @return string
     */
    abstract public function getLabel(): string;
}
