<?php

namespace Exceedone\Exment\Model;

use Encore\Admin\Form;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Form\PublicContent;
use Exceedone\Exment\DataItems\Show\PublicFormShow;
use Exceedone\Exment\DataItems\Form\PublicFormForm;
use Exceedone\Exment\Form\Field\ReCaptcha;

class PublicForm extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\AutoUuidTrait;
    use Traits\DatabaseJsonOptionTrait;
    use Traits\PublicFormInputTrait;

    protected $casts = ['options' => 'json'];

    public function custom_form()
    {
        return $this->belongsTo(CustomForm::class, 'custom_form_id');
    }

    public function notify_complete_admin()
    {
        return $this->hasOne(Notify::class, 'target_id')
            ->where('notify_trigger', NotifyTrigger::PUBLIC_FORM_COMPLETE_ADMIN)
            ->where('active_flg', 1);
    }
    public function notify_complete_user()
    {
        return $this->hasOne(Notify::class, 'target_id')
            ->where('notify_trigger', NotifyTrigger::PUBLIC_FORM_COMPLETE_USER)
            ->where('active_flg', 1);
    }
    public function notify_error()
    {
        return $this->hasOne(Notify::class, 'target_id')
            ->where('notify_trigger', NotifyTrigger::PUBLIC_FORM_ERROR)
            ->where('active_flg', 1);
    }

    public function deletingChildren()
    {
    }

    public function getCustomFormCacheAttribute()
    {
        return CustomForm::getEloquent($this->custom_form_id);
    }

    public function getCustomTableCacheAttribute()
    {
        $custom_form = $this->custom_form_cache;
        if(!$custom_form){
            return null;
        }
        return $custom_form->custom_table_cache;
    }


    protected static function boot()
    {
        parent::boot();
        
        static::deleting(function ($model) {
            $model->deletingChildren();
        });

        static::saved(function ($model) {
            $model->toggleNotify();
        });
    }


    /**
     * Get form base path
     *
     * @return string
     */
    public function getBasePath() : string
    {
        return url_join(config('exment.publicform_route_prefix', 'publicform'), $this->uuid);
    }

    /**
     * Get form url
     *
     * @return string
     */
    public function getUrl() : string
    {
        return asset_urls($this->getBasePath());
    }


    /**
     * Get form api url
     *
     * @return string
     */
    public function getApiUrl() : string
    {
        return asset_urls('publicformapi', $this->uuid);
    }


    /**
     * Get key's uuid by request
     *
     * @return string|null
     */
    public static function getUuidByRequest() : ?string
    {
        $segments = request()->segments();
        if(count($segments) < 2){
            return null;
        }

        if($segments[0] !== config('exment.publicform_route_prefix', 'publicform')
            && $segments[0] !== config('exment.publicformapi_route_prefix', 'publicformapi')){
            return null;
        }

        return $segments[1];
    }

    /**
     * Get from by uuid
     *
     * @return PublicForm|null
     */
    public static function getPublicFormByUuid($uuid) : ?PublicForm
    {
        if(!$uuid){
            return null;
        }

        $model = PublicForm::findByUuid($uuid);
        if(!$model){
            return null;
        }

        if(!boolval($model->active_flg)){
            return null;
        }

        // check daterange
        $now = Carbon::now();
        $start = $model->getOption('validity_period_start');
        if(!is_nullorempty($start) && Carbon::parse($start)->gt($now)){
            return null;
        }
        
        $end = $model->getOption('validity_period_end');
        if(!is_nullorempty($end) && Carbon::parse($end)->lt($now)){
            return null;
        }

        return $model;
    }


    /**
     * Get from by uuid
     *
     * @return PublicForm|null
     */
    public static function getPublicFormByRequest() : ?PublicForm
    {
        $uuid = static::getUuidByRequest();
        return static::getPublicFormByUuid($uuid);
    }


    /**
     * Get list of tables used.
     * Ex: 
     *     "Contact" form contains "select_table" column "client" and "product", return these custom table.
     *
     * @return Collection
     */
    public function getListOfTablesUsed() : Collection{
        $result = collect();
        foreach($this->custom_form->custom_form_blocks as $custom_form_block){
            if(!$custom_form_block->available){
                continue;
            }

            // if  FormBlockType is n:n, set target table
            if($custom_form_block->form_block_type == FormBlockType::MANY_TO_MANY){
                $result->push($custom_form_block->target_table_cache);
                continue;
            }

            foreach($custom_form_block->custom_form_columns as $custom_form_column){
                $custom_column = $custom_form_column->custom_column_cache;
                if(!$custom_column){
                    continue;
                }

                $select_target_table = $custom_column->select_target_table;   
                if(!$select_target_table){
                    continue;
                }

                $result->push($select_target_table);
            }
        }

        // check 1:n parent relation
        $parent_relation = CustomRelation::getRelationByChild($this->custom_table_cache, RelationType::ONE_TO_MANY);
        if($parent_relation){
            $result->push($parent_relation->parent_custom_table_cache);
        }

        return $result->unique();
    }

    
    /**
     * Get form
     *
     * @param Request $request
     * @param CustomValue|null $custom_value input custom value
     * @param boolean $setRecaptcha if true, set Recaptcha. If confirm→submit, set false
     * @return void
     */
    public function getForm(Request $request, ?CustomValue $custom_value = null, array $options = [])
    {
        $options = array_merge([
            'setRecaptcha' => true,
            'asConfirm' => false,
        ], $options);
        $setRecaptcha = $options['setRecaptcha'];


        // set footer as PublicFormFooter
        \Encore\Admin\Form\Builder::$footerClassName = \Exceedone\Exment\Form\PublicFormFooter::class;

        $custom_form = $this->custom_form;
        if(!$custom_form){
            return null;
        }
        $public_form = PublicFormForm::getItem($this->custom_table_cache, $this->custom_form_cache)
        ->setPublicForm($this)
        ->setAsConfirm($options['asConfirm'])
        ->setEnableDefaultQuery(boolval($this->getOption('use_default_query')));
    
        $form = $public_form->form()
            ->renderException(function($ex){
                return $this->showError($ex, true);
            })
            ->disablePjax()
            ->setView('exment::public-form.form')
            ->setAction($this->getUrl())
            ->setClass('block_custom_value_form')
            ;

        if($custom_value){
            $form->replicate($custom_value);
        }

        // get footer
        $footer = $form->builder()->getFooter();
        // Google recaptcha
        if($setRecaptcha && static::isEnableRecaptcha() && boolval($this->getOption('use_recaptcha', false))){
            $version = static::recaptchaVersion();
            if($version == 'v2'){
                $footer->useRecaptchaV2();
                $form->pushField(new ReCaptcha);
            }
            elseif($version == 'v3'){
                $footer->useRecaptchaV3();
                $form->pushField(new ReCaptcha);
            }
        }

        $form->submitLabel(boolval($this->getOption('use_confirm')) ? exmtrans('custom_form_public.confirm_label') : trans('admin.submit'));
        $form->hidden('formkey')->default($this->uuid);
        $form->hidden('publicformapi')->default(config('exment.publicformapi_route_prefix', 'publicformapi'));
        $form->hidden('rooturi')->default(asset(''));

        $form->ignore('formkey');
        $form->ignore('publicformapi');
        $form->ignore('rooturi');

        // Set custom css and js
        \Exceedone\Exment\Middleware\BootstrapPublicForm::setPublicFormCssJs($this);

        return $form;
    }

    
    /**
     * Get show
     *
     * @param Request $request
     * @return Form
     */
    public function getShow(Request $request, CustomValue $custom_value, array $inputs = [])
    {
        $custom_form = $this->custom_form;
        if(!$custom_form){
            return null;
        }

        $show_item = PublicFormShow::getItem($custom_value->custom_table, $custom_form)
            ->custom_value($custom_value)
            ->setPublicForm($this);
        $child_items = $show_item->getChildRelationShows($inputs);

        $show = $show_item
            ->createShowForm()
            ->renderException(function($ex){
                return $this->showError($ex, true);
            })
            ->setAction(url_join($this->getUrl(),  'create'))
            ->setBackAction($this->getUrl())
            ->setConfirmTitle(replaceTextFromFormat($this->getOption('confirm_title'), $custom_value))
            ->setConfirmText(replaceTextFromFormat($this->getOption('confirm_text'), $custom_value))
            ->setChildRelationShows($child_items);
            ;

        // Set custom css and js
        \Exceedone\Exment\Middleware\BootstrapPublicForm::setPublicFormCssJs($this);

        return $show;
    }

    
    /**
     * getCompleteView
     *
     * @param Request $request
     * @return Form
     */
    public function getCompleteView(Request $request, CustomValue $custom_value)
    {
        // create link
        $text = $this->getOption('complete_link_text');
        if(($url = $this->getOption('complete_link_url'))){
            $link = view('exment::tools.link', [
                'href' => $url,
                'label' => $text ?? $url,
            ]);
        }

        // Set custom css and js
        \Exceedone\Exment\Middleware\BootstrapPublicForm::setPublicFormCssJs($this);

        return view('exment::public-form.complete', [
            'model' => $custom_value,
            'complete_title' => replaceTextFromFormat($this->getOption('complete_title'), $custom_value),
            'complete_text' => replaceTextFromFormat($this->getOption('complete_text'), $custom_value),
            'link' => $link ?? null,
        ]);
    }
    
    /**
     * getErrorView
     *
     * @param Request $request
     * @return Form
     */
    public function getErrorView(Request $request)
    {
        // create link
        $text = $this->getOption('error_link_text');
        if(($url = $this->getOption('error_link_url'))){
            $link = view('exment::tools.link', [
                'href' => $url,
                'label' => $text ?? $url,
            ]);
        }

        // Set custom css and js
        \Exceedone\Exment\Middleware\BootstrapPublicForm::setPublicFormCssJs($this);
        
        return view('exment::public-form.error', [
            'error_title' => $this->getOption('error_title'),
            'error_text' => $this->getOption('error_text'),
            'link' => $link ?? null,
        ]);
    }


    /**
     * Show error page and notify
     *
     * @return void
     */
    public function showError($ex, $asInner = false){
        try{
            \Log::error($ex);

            try{
                if(!is_null($notify = $this->notify_error)){
                    $notify->notifyUser(null, [
                        'custom_table' => $this->custom_table_cache,
                        'prms' => [
                            'error:message' => $ex->getMessage(),
                            'error:stacktrace' => $ex->getTraceAsString(),
                            'publicform:public_form_view_name' => $this->public_form_view_name,
                        ],
                    ]);
                }
            }
            catch(\Exception $ex){
                \Log::error($ex);
            }

            $view = $this->getErrorView(request());
            if($asInner){
                return $view;
            }
            $content = new PublicContent;
            $this->setContentOption($content);
            $content->row($view);

            return $content;
        }
        catch(\Excedption $ex){
            throw $ex;
        } catch (\Throwable $ex) {
            throw $ex;
        }
    }


    /**
     * Set content option
     *
     * @param PublicContent $content
     * @return $this
     */
    public function setContentOption(PublicContent $content, array $options = [])
    {
        \Admin::css(asset('vendor/exment/css/publicform.css'));
        $options = array_merge([
                'add_analytics' => true,
                'isContainer' => false,
            ],
            $options
        );

        $header_logo = $this->getOption('header_logo');
        if(isset($header_logo)){
            $data = File::getData($header_logo);
            $header_logo = $data ? File::getUrl($data, ['asPublicForm' => true, 'publicFormKey' => $this->uuid]) : null;
        }
        $content->setBackgroundColor($this->getOption('background_color') ?? '#FFFFFF')
            ->setBackgroundColorOuter($this->getOption('background_color_outer') ?? '#FFFFFF')
            ->setHeaderBackgroundColor($this->getOption('header_background_color'))
            ->setFooterBackgroundColor($this->getOption('footer_background_color'))
            ->setFooterTextColor($this->getOption('footer_text_color') ?? '#FFFFFF')
            ->setUseHeader($this->getOption('use_header') ?? true)
            ->setUseFooter($this->getOption('use_footer') ?? true)
            ->setIsContainer($options['isContainer'])
            ->setHeaderLogoUrl($header_logo)
            ->setHeaderLabel($this->getOption('header_label'))
            ;

        // set analytics
        if($options['add_analytics']){
            $content->setAnalytics($this->getOption('analytics_tag'));
        }

        return $this;
    }


    /**
     * Get secret key for Google reCaptcha
     *
     * @return string|null
     */
    public static function recaptchaVersion() : ?string
    {
        return System::recaptcha_type() ?? config('no-captcha.version');
    }

    /**
     * Get site key for Google reCaptcha
     *
     * @return string|null
     */
    public static function recaptchaSiteKey() : ?string
    {
        return config('no-captcha.sitekey') ?? System::recaptcha_site_key();
    }

    /**
     * Get secret key for Google reCaptcha
     *
     * @return string|null
     */
    public static function recaptchaSecretKey() : ?string
    {
        return config('no-captcha.secret') ?? System::recaptcha_secret_key();
    }

    /**
     * Whether enable use reCAPTCHA
     *
     * @return true|string If true, enable reCaptcha. If string, showing message.
     */
    public static function isEnableRecaptcha()
    {
        $message = null;
        // checking NoCaptcha
        if(!\Exment::isAvailableGoogleRecaptcha()){
            $message = exmtrans('login.message.not_install_library', [
                'name' => 'Google reCaptcha',
                'url' => getManualUrl('public_form#recaptcha'),
            ]);
        }
        // check system setting
        else{
            $type = System::recaptcha_type();
            $site_key = static::recaptchaSiteKey();
            $secret = static::recaptchaSecretKey();
            if(is_nullorempty($type) || is_nullorempty($site_key) || is_nullorempty($secret)){
                $message = exmtrans('custom_form_public.message.recaptcha_not_setting');
            }
        }

        if($message){
            return '<span class="red">' . $message . '</span>';
        }
        return true;
    }


    /**
     * Get css and js plugins
     *
     * @return Collection
     */
    public function getCssJsPlugins()
    {
        $result = collect();
        foreach(['css', 'js'] as $p){
            $pluginIds = $this->getOption("plugin_{$p}") ?? [];
            foreach($pluginIds as $pluginId){
                $plugin = Plugin::getEloquent($pluginId);
                $result->push($plugin);
            }
        }

        return $result;
    }

}
