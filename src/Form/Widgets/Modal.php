<?php

namespace Exceedone\Exment\Form\Widgets;

use Encore\Admin\Facades\Admin;

class Modal
{
    use ModalTrait;
    protected $modalBody;

    public function body($modalBody)
    {
        $this->modalBody = $modalBody;
    }

    protected function script()
    {
        // modal id
        $id = $this->modalAttributes['id'];
        // Add script
        $script = <<<EOT
            $(document).off('click', '[data-widgetmodal_url]').on('click', '[data-widgetmodal_url]', {}, function(ev){
                var url = $(ev.target).closest('[data-widgetmodal_url]').data('widgetmodal_url');
                // get ajax
                $.ajax({
                    url: url,
                    method: 'GET',
                }).done(function( res ) {
                    // change html
                    $('#$id .modal-body').html(res);
                    if(!$('#$id').hasClass('in')){
                        $('#$id').modal('show');
                    }
                }).fail(function( res, textStatus, errorThrown ) {
                    
                }).always(function(res){
                });
            });
            $(document).off('click', '#$id .modal-body a').on('click', '#$id .modal-body a', {}, function(ev){
                if($(ev.target).closest('a[data-widgetmodal_url]').length > 0){
                    return;
                }
                $('#$id .modal-body').html('');
                $('#$id').modal('hide');
            });
EOT;
        Admin::script($script);
    }

    public static function widgetModalRender()
    {
        // add modal for showmodal
        $modal = new Modal();
        $modal->modalHeader('データ確認'); //TODO:trans
        $modal->modalAttribute(['id' => 'modal-showmodal', 'data-backdrop' => true]);

        return $modal->render();
    }

    /**
     * Render the form.
     *
     * @return string
     */
    public function render()
    {
        $this->setModalAttributes();

        $this->script();

        // get view
        return view('exment::widgets.modal', [
            'header' => $this->modalHeader,
            'body' => $this->modalBody,
            'submit' => false,
            'modalAttributes' => $this->convert_attribute($this->modalAttributes),
            'modalInnerAttributes' => $this->convert_attribute($this->modalInnerAttributes),
        ]);
    }
}
