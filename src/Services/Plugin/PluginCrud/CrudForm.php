<?php
namespace Exceedone\Exment\Services\Plugin\PluginCrud;

use Encore\Admin\Widgets\Form;
use Encore\Admin\Widgets\Grid\Grid;
use Illuminate\Http\Request;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Widgets\Box;
use Exceedone\Exment\Form\Tools;

/**
 * Form for Plugin CRUD(and List)
 */
class CrudForm extends CrudBase
{
    /**
     * Create
     *
     * @param Request $request
     * @return void
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
     * @param mixed $id
     * @return void
     */
    public function edit($id)
    {
        $content = $this->pluginClass->getContent();
        
        $content->body($this->form(false, $id)->render());

        return $content;
    }

    /**
     * Store
     *
     * @return void
     */
    public function store()
    {
        $content = $this->pluginClass->getContent();
        
        return $this->save(true);
    }

    /**
     * Update
     *
     * @return void
     */
    public function update($id)
    {
        $content = $this->pluginClass->getContent();
        
        return $this->save(false, $id);
    }

    /**
     * delete
     *
     * @return void
     */
    public function delete($id)
    {
        $ids = stringToArray($id);
        $this->pluginClass->delete($ids);
        
        return $this->getFullUrl();
    }

    
    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form(bool $isCreate, $id = null)
    {
        $form = $this->getForm($isCreate, $id);

        $box = new Box(trans($isCreate ? 'admin.create' : 'admin.edit'), $form);
        $box->style('info');
        $this->setFormTools($id, $box);

        if($isCreate){
            $this->pluginClass->callbackCreate($form, $box);
        }
        else{
            $this->pluginClass->callbackEdit($form, $box);
        }

        return $box;
    }


    /**
     * Save value.
     *
     * @return Form
     */
    protected function save(bool $isCreate, $id = null)
    {
        $values = $this->filterPostedValue(request()->all(), $isCreate);
        $form = $this->getForm($isCreate, $id);

        // validate
        $validateResult = $this->pluginClass->validate($form, $values, $isCreate, $id);

        //ToDo:validation error
        if($validateResult->any()){
            return back()->withInput($values);
        }

        // save value
        if($isCreate){
            $value = $this->pluginClass->postCreate($values);
        }
        else{
            $value = $this->pluginClass->putEdit($id, $values);
        }

        //ToDo:修正。配列かオブジェクトの場合
        return redirect($this->getFullUrl($value));
    }

    /**
     * Filter posted value  for input target
     *
     * @param array $array
     * @param boolean $isCreate
     * @return array
     */
    protected function filterPostedValue(array $array, bool $isCreate) : array
    {
        $key = $isCreate ? 'create' : 'edit';
        $definitions = collect($this->pluginClass->getFieldDefinitions())
            ->filter(function($d) use($key){
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
    protected function getForm(bool $isCreate, $id = null) : WidgetForm
    {
        if($isCreate){
            $data = [];
        }
        else{
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
     * @param Form $form
     * @return void
     */
    protected function setFormColumn(bool $isCreate, Form $form)
    {
        $key = $isCreate ? 'create' : 'edit';
        $definitions = collect($this->pluginClass->getFieldDefinitions())
            ->filter(function($d) use($key){
                return array_has($d, $key);
            })->sortBy($key);

        // get primary key
        $primary = $this->pluginClass->getPrimaryKey();

        foreach($definitions as $target){
            // if primary key, only show.
            if($primary == array_get($target, 'key')){
                $this->pluginClass->setFormPrimaryDifinition($form, array_get($target, 'key'), array_get($target, 'label'));
            }
            elseif($isCreate){
                $this->pluginClass->setCreateColumnDifinition($form, array_get($target, 'key'), array_get($target, 'label'));
            }
            else{
                $this->pluginClass->setEditColumnDifinition($form, array_get($target, 'key'), array_get($target, 'label'));
            }
        }
    }

    /**
     * Set form tools.
     *
     * @param Box $Box
     * @return void
     */
    protected function setFormTools($id, Box $box)
    {
        if($this->pluginClass->enableDelete($id))
        {
            $box->tools((new Tools\DeleteButton(admin_url($this->getFullUrl($id))))->render());
        }

        $box->tools(view('exment::tools.button', [
                'href' => admin_url($this->getFullUrl()),
                'label' => trans('admin.list'),
                'icon' => 'fa-list',
                'btn_class' => 'btn-default',
            ])->render());

        if($this->pluginClass->enableShow($id)){
            $box->tools(view('exment::tools.button', [
                'href' => admin_url($this->getFullUrl($id)),
                'label' => trans('admin.show'),
                'icon' => 'fa-eye',
                'btn_class' => 'btn-primary',
            ])->render());
        }

        $this->pluginClass->callbackFormTool($box);
    }
}
