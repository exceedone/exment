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

    public function plugin($plugin)
    {
        $this->plugin = Plugin::getPluginByUUID($plugin);

        return $this;
    }

    public function datalist()
    {
        $providers = [];

        // get default data
        // todo プラグインエクスポートで通常ビューのプロバイダーを使うための修正です
        $providers[] = new Export\ViewProvider([
            'custom_table' => $this->custom_table,
            'custom_view' => $this->custom_view,
            'grid' => $this->grid
        ]);

        $datalist = [];
        foreach ($providers as $provider) {
            if (!$provider->isOutput()) {
                continue;
            }

            $datalist[] = ['name' => $provider->name(), 'outputs' => $provider->data()];
            $this->count .= $provider->getCount();
        }

        return $datalist;
    }

    /**
     * Execute output
     *
     * @return void
     */
    public function execute()
    {
        $this->plugin(request()->get('plugin_uuid'));

        $pluginClass = $this->plugin->getClass(PluginType::EXPORT, ['custom_table' => $this->custom_table]);

        $pluginClass->defaultProvider(new Export\DefaultTableProvider([
            'custom_table' => $this->custom_table,
            'grid' => $this->grid
        ]));

        // todo プラグインエクスポートで通常ビューのプロバイダーを使うための修正です
        $pluginClass->viewProvider(new Export\ViewProvider([
            'custom_table' => $this->custom_table,
            'custom_view' => $this->custom_view,
            'grid' => $this->grid
        ]));

        $file = null;
        try {
            $file = $pluginClass->execute();

            $response = response()->download($file, $pluginClass->getFileName());

            // if string(tmp file), set deleteFileAfterSend
            if (is_string($file)) {
                $response->deleteFileAfterSend(true);
            }

            $response->send();
            exit;
        }
        // Delete if exception
        finally {
            if (isset($file) && is_string($file) && \File::exists($file)) {
                \File::delete($file);
            }
        }
    }
}
