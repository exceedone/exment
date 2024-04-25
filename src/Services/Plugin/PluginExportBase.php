<?php

namespace Exceedone\Exment\Services\Plugin;

use Exceedone\Exment\Model\Define;

/**
 * Plugin (Export) base class
 */
abstract class PluginExportBase
{
    use PluginBase;

    protected $custom_table;

    /**
     * Provider as default
     *
     * @var \Exceedone\Exment\Services\DataImportExport\Providers\Export\ProviderBase
     */
    protected $default_provider;

    /**
     * Provider as view
     *
     * @var \Exceedone\Exment\Services\DataImportExport\Providers\Export\ProviderBase
     */
    protected $view_provider;

    /**
     * Tmp full path.
     *
     * @var string|null
     */
    protected $tmpFullPath;


    public function __construct($plugin, $custom_table)
    {
        $this->plugin = $plugin;
        $this->custom_table = $custom_table;
    }


    public function defaultProvider($default_provider)
    {
        $this->default_provider = $default_provider;

        return $this;
    }


    public function viewProvider($view_provider)
    {
        $this->view_provider = $view_provider;

        return $this;
    }


    /**
     * Get grid data.
     */
    protected function getData()
    {
        return $this->combineData($this->default_provider->data());
    }

    /**
     * Get view's data.
     *
     * @return array
     */
    protected function getViewData()
    {
        return $this->combineData($this->view_provider->data());
    }


    /**
     * Combine data
     *
     * @param array $data
     * @return array
     */
    protected function combineData($data)
    {
        $headers = $data[0];

        $bodies = collect(array_slice($data, 2))->map(function ($d) use ($headers) {
            return array_combine($headers, $d);
        })->toArray();

        return $bodies;
    }


    /**
     * Get CustomValue's records.
     */
    protected function getRecords()
    {
        return $this->default_provider->getRecords();
    }


    /**
     * get Directory full path from root
     * @return string File path
     */
    public function getTmpFullPath()
    {
        if (isset($this->tmpFullPath)) {
            return $this->tmpFullPath;
        }

        $file = make_uuid();
        $this->tmpFullPath = getFullpath($file, Define::DISKNAME_ADMIN_TMP);
        return $this->tmpFullPath;
    }


    /**
     * Execute exporting data
     *
     * @return string|mixed
     * string: Tmp file path. if response, delete tmp file auto.
     */
    abstract public function execute();


    /**
     * Get download file name.
     *
     * @return string
     */
    abstract public function getFileName(): string;
}
