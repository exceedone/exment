<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Export;

use Exceedone\Exment\Services\DataImportExport\Providers\Export;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\PluginType;

/**
 * Export using Plugin
 */
class PluginAction extends CustomTableAction
{
    protected $custom_view;
    
    protected $plugin;
    
    public function __construct($args = [])
    {
        $this->custom_table = array_get($args, 'custom_table');

        $this->custom_view = array_get($args, 'custom_view');

        $this->grid = array_get($args, 'grid');
    }

    public function plugin($plugin){
        $this->plugin = Plugin::getEloquent($plugin);

        return $this;
    }

    public function datalist()
    {
        $providers = [];

        // get default data
        $providers[] = new Export\SummaryProvider([
            'custom_table' => $this->custom_table,
            'custom_view' => $this->custom_view,
            'is_summary' => $this->is_summary,
            'grid' => $this->grid
        ]);
        
        $datalist = [];
        foreach ($providers as $provider) {
            if (!$provider->isOutput()) {
                continue;
            }

            $datalist[] = ['name' => $provider->name(), 'outputs' => $provider->data()];
        }

        return $datalist;
    }

    /**
     * Execute output
     *
     * @return void
     */
    public function execute(){
        $this->plugin(request()->get('plugin_id'));

        $pluginClass = $this->plugin->getClass(PluginType::EXPORT);

        $pluginClass->defaultProvider(new Export\DefaultTableProvider([
            'custom_table' => $this->custom_table,
            'grid' => $this->grid
        ]));

        $pluginClass->viewProvider(new Export\SummaryProvider([
            'custom_table' => $this->custom_table,
            'custom_view' => $this->custom_view,
            //'is_summary' => $this->is_summary,
            'grid' => $this->grid
        ]));

        $fileFullPath = null;
        try{
            $fileFullPath = $pluginClass->execute();

            $response = response()->download($fileFullPath, $pluginClass->getFileName());
            
            // if string(tmp file), set deleteFileAfterSend
            if(is_string($fileFullPath)){
                $response->deleteFileAfterSend(true);
            }
    
            $response->send();
            exit;
        }
        // Delete if exception
        finally{
            if(isset($fileFullPath) && \File::exists($fileFullPath)){
                \File::delete($fileFullPath);
            }
        }

    }
}
