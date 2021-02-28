<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Routing\Controller;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Form\PublicContent;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomFormPriority;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums\FormLabelType;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Services\FormSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Custom Form Controller
 */
class PublicFormController extends Controller
{
    /**
     * @var PublicForm
     */
    protected $public_form;

    /**
     */
    protected $form_item;

    /**
     * @var CustomForm
     */
    protected $custom_form;
    
    /**
     * @var CustomTable
     */
    protected $custom_table;

    public function __construct(?PublicForm $public_form)
    {
        $this->public_form = $public_form;
        if(isset($public_form)){
            $this->custom_form = $public_form->custom_form;
        }
        if(isset($this->custom_form)){
            $this->form_item = $this->custom_form->form_item;
            $this->custom_table = $this->custom_form->custom_table;
            getModelName($this->custom_table);
        }
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request)
    {
        return $this->getInputContent($request);
    }


    /**
     * Backed interface.
     *
     * @return Content
     */
    public function backed(Request $request)
    {
        $inputs = $request->session()->pull(Define::SYSTEM_KEY_SESSION_PUBLIC_FORM_INPUT);

        return redirect($this->public_form->getUrl())->withInput($inputs);
    }


    /**
     * Get input content (Contains form)
     *
     * @param Request $request
     * @return void
     */
    protected function getInputContent(Request $request)
    {
        try{
            $uri = boolval($this->public_form->getOption('use_confirm')) ? 'confirm' : 'create';

            $form = $this->public_form->getForm($request)
                ->setAction(url_join($this->public_form->getUrl(),  $uri));
    
            $content = new PublicContent;
            $this->public_form->setContentOption($content);
    
            $content->row($form);
            return $content;
        }
        catch(\Exception $ex){
            return $this->public_form->showError($ex);
        } catch (\Throwable $ex) {
            return $this->public_form->showError($ex);
        }
    }


    /**
     * confirm interface.
     *
     * @return Content
     */
    public function confirm(Request $request)
    {
        try {
            $form = $this->public_form->getForm($request, null, [
                'asConfirm' => true,
            ]);
        
            //validate
            $response = $form->validateRedirect($request->all());
            if ($response instanceof Response) {
                return $response;
            }

            $custom_value = $form->getModelByInputs();

            // set session
            $inputs = $this->removeUploadedFile($request->all());
            $request->session()->put(Define::SYSTEM_KEY_SESSION_PUBLIC_FORM_INPUT, $inputs);

            $show = $this->public_form->getShow($request, $custom_value, $inputs);

            $content = new PublicContent;
            $this->public_form->setContentOption($content);

            $content->row($show);
            return $content;
        } catch (\Exception $ex) {
            return $this->public_form->showError($ex);
        } catch (\Throwable $ex) {
            return $this->public_form->showError($ex);
        }
    }



    /**
     * create interface.
     *
     * @return Content
     */
    public function create(Request $request)
    {
        try {
            $form = $this->public_form->getForm($request, null, ['setRecaptcha' => false]);
            $public_form = $this->public_form;
            
            $form->saving(function($form) use($request, $public_form){
                // Disable saved notify
                $form->model()->saved_notify(false);
            });

            // notify
            $form->savedInTransaction(function ($form) use($public_form) {
                $model = $form->model();

                if(!is_null($notify = $public_form->notify_complete_admin)){
                    $notify->notifyUser($model);
                }
                
                if(!is_null($notify = $public_form->notify_complete_user)){
                    $notify->notifyUser($model);
                }
            });

            $form->saved(function($form) use($request, $public_form){
                $content = new PublicContent;
                $public_form->setContentOption($content);

                $content->row($public_form->getCompleteView($request, $form->model()));

                return response($content);
            });

            // get data by session or result
            $data = $request->session()->has(Define::SYSTEM_KEY_SESSION_PUBLIC_FORM_INPUT) ? $request->session()->pull(Define::SYSTEM_KEY_SESSION_PUBLIC_FORM_INPUT) : $request->all();
            $response = $form->store($data);

            // Disable reload
            $request->session()->regenerateToken();

            return $response;
            
        } catch (\Exception $ex) {
            return $this->public_form->showError($ex);
        } catch (\Throwable $ex) {
            return $this->public_form->showError($ex);
        }
    }


    /**
     * Remove uploaded file. For setting session.
     *
     * @param array $inputs
     * @return array
     */
    protected function removeUploadedFile(array $inputs) : array{
        foreach($inputs as &$input){
            if(is_array($input)){
                $input = $this->removeUploadedFile($input);
            }
            // $input is uploaded file, set requestsession key name
            if($input instanceof \Illuminate\Http\UploadedFile){
                $hashName = $input->hashName();
                $input = System::requestSession(Define::SYSTEM_KEY_SESSION_PUBLIC_FORM_INPUT_FILENAMES . $hashName);
            }
        }

        return $inputs;
    }
}
