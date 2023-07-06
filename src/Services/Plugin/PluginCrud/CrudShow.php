<?php

namespace Exceedone\Exment\Services\Plugin\PluginCrud;

use Encore\Admin\Widgets\Form;
use Illuminate\Http\Request;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Widgets\Box;
use Exceedone\Exment\Form\Tools;

/**
 * Show for Plugin CRUD(and List)
 */
class CrudShow extends CrudBase
{
    /**
     * Show. for detail.
     *
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        $content = $this->pluginClass->getContent();

        $content->body($this->detail($id)->render());

        return $content;
    }

    /**
     * Make a show builder.
     *
     * @param $id
     * @return Box
     */
    protected function detail($id)
    {
        $data = $this->pluginClass->getData($id);

        $form = new WidgetForm((array)$data);
        $form->disableReset();
        $form->disableSubmit();

        $this->setShowColumn($form);

        $box = new Box(trans('admin.detail'), $form);
        $box->style('info');
        $this->setShowTools($id, $box);

        $this->pluginClass->callbackShow($id, $form, $box);

        return $box;
    }


    /**
     * Set form definitions.
     *
     * @param Form $form
     * @return void
     */
    protected function setShowColumn(Form $form)
    {
        $definitions = collect($this->pluginClass->getFieldDefinitions())
            ->filter(function ($d) {
                return array_has($d, 'show');
            })->sortBy('show');

        foreach ($definitions as $target) {
            $this->pluginClass->setShowColumnDifinition($form, array_get($target, 'key'), array_get($target, 'label'));
        }
    }

    /**
     * Set form tools.
     *
     * @param $id
     * @param Box $box
     * @return void
     */
    protected function setShowTools($id, Box $box)
    {
        // get oauth logout view
        $oauthLogoutView = $this->getOAuthLogoutView();
        if ($oauthLogoutView) {
            $box->tools($oauthLogoutView->render());
        }

        if ($this->pluginClass->enableDeleteAll() && $this->pluginClass->enableDelete($id)) {
            $box->tools((new Tools\DeleteButton(admin_url($this->getFullUrl($id))))->render());
        }

        if ($this->pluginClass->enableEditAll() && $this->pluginClass->enableEdit($id)) {
            $box->tools(view('exment::tools.button', [
                'href' => admin_url($this->getFullUrl(url_join($id, 'edit'))),
                'label' => trans('admin.edit'),
                'icon' => 'fa-edit',
                'btn_class' => 'btn-primary',
            ])->render());
        }

        $box->tools(view('exment::tools.button', [
                'href' => admin_url($this->getFullUrl()),
                'label' => trans('admin.list'),
                'icon' => 'fa-list',
                'btn_class' => 'btn-default',
            ])->render());

        $this->pluginClass->callbackShowTool($id, $box);
    }
}
