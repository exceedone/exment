<?php

namespace Exceedone\Exment\Services\Plugin;

use Exceedone\Exment\Model\PublicForm;

/**
 * Plugin (Style, Script) base class
 */
class PluginPublicBase
{
    protected $plugin;

    public function _plugin()
    {
        return $this->plugin;
    }

    /**
     * get css files
     *
     * @return array
     */
    public function css($skipPath = false)
    {
        return $this->getCssJsFiles($skipPath ? null : 'css', 'css');
    }

    /**
     * get js path
     *
     * @param $skipPath
     * @return array
     */
    public function js($skipPath = false)
    {
        return $this->getCssJsFiles($skipPath ? null : 'js', 'js');
    }

    /**
     * get public path
     *
     * @param $path
     * @param $type
     * @return array|mixed[]
     */
    protected function getCssJsFiles($path, $type)
    {
        $base_path = $this->plugin->getFullPath('public');
        $type_path = path_join($base_path, $path);
        if (!\File::exists($type_path)) {
            return [];
        }

        // get files
        $files = \File::allFiles($type_path);

        return collect($files)->filter(function ($file) use ($type) {
            return pathinfo($file)['extension'] == $type;
        })->map(function ($file) use ($base_path) {
            $path = trim(str_replace($base_path, '', $file->getPathName()), '/');
            return str_replace('\\', '/', $path);
        })->toArray();
    }

    /**
     * Get css and js url
     *
     * @param $fileName
     * @param bool $asPublicForm
     * @return mixed|string|null
     */
    public function getCssJsUrl($fileName, bool $asPublicForm = false)
    {
        if ($asPublicForm) {
            $public_form = PublicForm::getPublicFormByRequest();
            if (!$public_form) {
                return null;
            }

            $url = $public_form->getUrl();
        } else {
            $url = admin_urls();
        }

        return url_join($url, $this->plugin->getRouteUri(), 'public', $fileName);
    }
}
