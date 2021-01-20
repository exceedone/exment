<?php
namespace Exceedone\Exment\Services\FormSetting\FormColumn;

use Exceedone\Exment\Services\FormSetting\FormBlock\BlockBase;
use Encore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Model\File as ExmentFile;

/**
 */
class Image extends OtherBase
{
    /**
     * Get setting modal form 
     *
     * @return WidgetForm
     */
    public function getSettingModalForm(BlockBase $block_item, array $parameters) : WidgetForm
    {
        $form = new WidgetForm($parameters);

        $imageurl = $this->getImageUrl();
        if(!isset($imageurl)){
            $form->image('image', exmtrans('custom_form.image'))
                ->attributes(['accept' => '.jpeg,.jpg,.png,.gif,.svg']);
        }
        else{
            $form->description($imageurl)->escape(false)
                ->help(exmtrans('custom_form.message.image_need_delete'));
        }
        $form->switchbool('image_aslink', exmtrans('custom_form.image_aslink'))->default(false)
            ->help(exmtrans('custom_form.help.image_aslink'));

        return $form;
    }

    
    /**
     * getImageUrl
     *
     * @return string|null
     */
    protected function getImageUrl() : ?string
    {
        $file = ExmentFile::getFileFromFormColumn(array_get($this->custom_form_column, 'id'));
        if(!$file){
            return null;
        }
        return ExmentFile::getUrl($file);
    }

    

    /**
     * prepare saving option.
     *
     * @return array|string
     */
    public function prepareSavingOptions(array $options) : array
    {
        return [];
    }
}
