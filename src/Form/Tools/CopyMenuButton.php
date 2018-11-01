<?php

namespace Exceedone\Exment\Form\Tools;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Services\FormHelper;

/**
 * Copy menu button.
 */
class CopyMenuButton
{
    protected $copy;
    protected $custom_table;
    protected $id;
    
    public function __construct($copy, $custom_table, $id = null){
        $this->copy = $copy;
        $this->custom_table = $custom_table;
        $this->id = $id;        
    }

    protected function script($uuid)
    {
        $table_name = array_get($this->custom_table, 'table_name');
        // create url
        if(isset($this->id)){
            $url = admin_base_path(url_join("data", $table_name, $this->id, "copyClick"));
        }else{
            $url = admin_base_path(url_join("data", $table_name, "copyClick"));
        }
        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');

        // TODO:下のメッセージは要変更
        return <<<EOT

        $('#menu_button_$uuid').off('click').on('click', function(){
            if($('#modal-form').length > 0){
                $('#modal-form').modal();
                return;
            }
            swal({
                title: "コピーを実行します。よろしいですか？",
                type: "warning",
                showCancelButton: true,
                confirmButtonColor: "#DD6B55",
                confirmButtonText: "$confirm",
                showLoaderOnConfirm: true,
                cancelButtonText: "$cancel",
                preConfirm: function() {
                    return new Promise(function(resolve) {
                        $.ajax({
                            type: "POST",
                            url: "$url",
                            //container: "#pjax-container",
                            data:{ _pjax: true, _token: LA.token,uuid:"$uuid"},
                            success:function(repsonse) {
                                Exment.CommonEvent.CallbackExmentAjax(res);
                            },
                            error: function(repsonse){
                                toastr.error(repsonse.message);
                            }
                        });
                    });
                }
            });
    
        });
EOT;
    }

    protected function copyModalForm($copy_input_columns, $label, $uuid){
        $from_table_view_name = $this->custom_table->table_view_name;
        $to_table_view_name = $this->copy->to_custom_table->table_view_name;
        $path = admin_base_path(url_join('data', $this->custom_table->table_name, $this->id, 'copyClick'));
        
        // create form fields
        $form = new \Exceedone\Exment\Form\Widgets\ModalForm();
        $form->action($path);
        $form->method('POST');
        $form->modalHeader($label);

        // add form
        $form->description("この{$from_table_view_name}のデータをもとに、{$to_table_view_name}を作成します。<br/>作成する{$to_table_view_name}のデータに登録する、値を記入してください。");
        foreach($copy_input_columns as $copy_input_column){
            $field = FormHelper::getFormField($this->custom_table, $copy_input_column->to_custom_column, null);
            $form->push_Field($field);
        }
        $form->hidden('uuid')->default($uuid);
        
        return $form->render()->render();
    }

    public function toHtml()
    {
        // get uuid
        $uuid = array_get($this->copy, 'suuid');
        Admin::script($this->script($uuid));

        // get button_class
        $button_class = array_get($this->copy, 'button_class');
        if(!isset($button_class)){
            $button_class = 'btn-default';
        }

        // get label
        if(!is_null(array_get($this->copy, 'options.label'))){
            $label = array_get($this->copy, 'options.label');
        }

        // get copy input fields
        $copy_input_columns = $this->copy->custom_copy_input_columns ?? [];
        // if has, create modalform
        if(count($copy_input_columns) > 0){
            $form_html = $this->copyModalForm($copy_input_columns, $label, $uuid);
        }


        return ($form_html ?? null) . view('exment::tools.plugin-menu-button', [
            'uuid' => $uuid,
            'label' => $label ?? null,
            'button_class' => $button_class
        ])->render();
    }
    
    /**
     * @return string
     */
    public function __toString()
    {
        return $this->render()->render() ?? '';
    }
}
