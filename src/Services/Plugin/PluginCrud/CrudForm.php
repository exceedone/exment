<?php

namespace Exceedone\Exment\Services\Plugin\PluginCrud;

use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Encore\Admin\Widgets\Form;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Widgets\Box;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Validator\ExmentCustomValidator;

/**
 * Form for Plugin CRUD(and List)
 */
class CrudForm extends CrudBase
{
    /**
     * Create
     *
     * @return mixed
     */
    public function create()
    {
        $content = $this->pluginClass->getContent();

        $content->body($this->form(true)->render());

        return $content;
    }

    /**
     * Edit
     *
     * @param $id
     * @return mixed
     */
    public function edit($id)
    {
        $content = $this->pluginClass->getContent();

        $content->body($this->form(false, $id)->render());

        return $content;
    }

    /**
     * Stor
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function store()
    {
        $content = $this->pluginClass->getContent();

        return $this->save(true);
    }

    /**
     * Update
     *
     * @param $id
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function update($id)
    {
        $content = $this->pluginClass->getContent();

        return $this->save(false, $id);
    }

    /**
     * delete
     *
     * @param $id
     * @return string
     */
    public function delete($id)
    {
        $ids = stringToArray($id);
        $this->pluginClass->deletes($ids);

        return $this->getFullUrl();
    }

    /**
     * Make a form builder.
     *
     * @param bool $isCreate
     * @param $id
     * @return Box
     */
    protected function form(bool $isCreate, $id = null)
    {
        $form = $this->getForm($isCreate, $id);

        $box = new Box(trans($isCreate ? 'admin.create' : 'admin.edit'), $form);
        $box->style('info');
        $this->setFormTools($id, $box);

        if ($isCreate) {
            $this->pluginClass->callbackCreate($form, $box);
        } else {
            $this->pluginClass->callbackEdit($id, $form, $box);
        }

        return $box;
    }


    /**
     * Save value.
     *
     * @param bool $isCreate
     * @param $id
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    protected function save(bool $isCreate, $id = null)
    {
        $values = $this->filterPostedValue(request()->all(), $isCreate);
        $form = $this->getForm($isCreate, $id);

        // validate
        $validateResult = $this->pluginClass->validate($form, $values, $isCreate, $id);

        /////validation error
        // If $validateResult is array, has items
        $isError = false;
        if (is_list($validateResult)) {
            $isError = count($validateResult) > 0;
        } elseif ($validateResult instanceof MessageBag) {
            $isError = $validateResult->any();
        } elseif ($validateResult instanceof ExmentCustomValidator) {
            $isError = $validateResult->fails();
            $validateResult = $validateResult->getMessages();
        }
        if ($isError) {
            return back()->withErrors($validateResult)->withInput($values);
        }

        // save value
        if ($isCreate) {
            $value = $this->pluginClass->postCreate($values);
        } else {
            $value = $this->pluginClass->putEdit($id, $values);
        }

        return redirect($this->getFullUrl($value));
    }

    /**
     * Filter posted value  for input target
     *
     * @param array $array
     * @param boolean $isCreate
     * @return array
     */
    protected function filterPostedValue(array $array, bool $isCreate): array
    {
        $key = $isCreate ? 'create' : 'edit';
        $definitions = collect($this->pluginClass->getFieldDefinitions())
            ->filter(function ($d) use ($key) {
                return array_has($d, $key) && !array_boolval($d, 'primary');
            })->map(function ($item, $key) {
                return array_get($item, 'key');
            })->toArray();


        return array_only($array, $definitions);
    }

    /**
     * Get form model.
     *
     * @param boolean $isCreate
     * @param mixed $id
     * @return WidgetForm
     */
    protected function getForm(bool $isCreate, $id = null): WidgetForm
    {
        if ($isCreate) {
            $data = [];
        } else {
            $data = $this->pluginClass->getData($id);
        }

        $form = new WidgetForm((array)$data);
        $form->disableReset()
            ->action($this->getFullUrl($isCreate ? '' : $id))
            ->method($isCreate ? 'POST' : 'PUT');

        $this->setFormColumn($isCreate, $form);

        return $form;
    }

    /**
     * Set form definitions.
     *
     * @param bool $isCreate
     * @param WidgetForm $form
     * @return void
     */
    protected function setFormColumn(bool $isCreate, Form $form)
    {
        $customForm = $this->pluginClass->setForm($form, $isCreate);
        if (!is_nullorempty($customForm)) {
            $form = $customForm;
        } else {
            $key = $isCreate ? 'create' : 'edit';
            $definitions = collect($this->pluginClass->getFieldDefinitions())
                ->filter(function ($d) use ($key) {
                    return array_has($d, $key);
                })->sortBy($key);

            // get primary key
            $primary = $this->pluginClass->getPrimaryKey();

            foreach ($definitions as $target) {
                // if primary key, only show.
                if ($primary == array_get($target, 'key')) {
                    $this->pluginClass->setFormPrimaryDifinition($form, array_get($target, 'key'), array_get($target, 'label'));
                } elseif ($isCreate) {
                    $this->pluginClass->setCreateColumnDifinition($form, array_get($target, 'key'), array_get($target, 'label'));
                } else {
                    $this->pluginClass->setEditColumnDifinition($form, array_get($target, 'key'), array_get($target, 'label'));
                }
            }
        }
    }

    /**
     * Set form tools.
     *
     * @param $id
     * @param Box $box
     * @return void
     */
    protected function setFormTools($id, Box $box)
    {
        // get oauth logout view
        $oauthLogoutView = $this->getOAuthLogoutView();
        if ($oauthLogoutView) {
            $box->tools($oauthLogoutView->render());
        }

        if ($this->pluginClass->enableDelete($id)) {
            $box->tools((new Tools\DeleteButton(admin_url($this->getFullUrl($id))))->render());
        }

        $box->tools(view('exment::tools.button', [
                'href' => admin_url($this->getFullUrl()),
                'label' => trans('admin.list'),
                'icon' => 'fa-list',
                'btn_class' => 'btn-default',
            ])->render());

        if ($this->pluginClass->enableShow($id)) {
            $box->tools(view('exment::tools.button', [
                'href' => admin_url($this->getFullUrl($id)),
                'label' => trans('admin.show'),
                'icon' => 'fa-eye',
                'btn_class' => 'btn-primary',
            ])->render());
        }

        $this->pluginClass->callbackFormTool($id, $box);
    }
}
