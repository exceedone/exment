<?php

namespace Exceedone\Exment\Form\Tools;

use Encore\Admin\Facades\Admin;

/**
 * delete button.
 */
class DeleteButton
{
    protected $url;

    public function __construct($url)
    {
        $this->url = $url;
    }

    protected function script($id)
    {
        $url = $this->url;
        $title = trans('admin.delete_confirm');
        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');
        return <<<EOT
        $('#{$id}').on('click', function(){
            Exment.CommonEvent.ShowSwal('$url', {
                title: "$title",
                method: 'delete',
                confirm:"$confirm",
                cancel:"$cancel"
            });
        })
EOT;
    }

    public function render()
    {
        $id =  'btn' . short_uuid();
        Admin::script($this->script($id));

        return view('exment::tools.button', [
            'href' => 'javascript::void();',
            'label' => trans('admin.delete'),
            'icon' => 'fa-trash',
            'btn_class' => 'btn-danger',
            'attributes' => ['id' => $id],
        ]);
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->render()->render() ?? '';
    }
}
