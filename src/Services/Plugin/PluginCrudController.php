<?php
namespace Exceedone\Exment\Services\Plugin;

use Encore\Admin\Widgets\Box;
use Exceedone\Exment\Enums\PluginCrudAuthType;
use Exceedone\Exment\Exceptions\SsoLoginErrorException;
use Exceedone\Exment\Services\Login\OAuth\OAuthService;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

class PluginCrudController extends Controller
{
    protected $pluginPage;
    protected $plugin;
    
    public function __construct(?PluginCrudBase $pluginPage)
    {
        $this->pluginPage = $pluginPage;
        $this->plugin = isset($pluginPage) ? $pluginPage->_plugin() : null ;
    }

    /**
     * Index. for grid.
     *
     * @return void
     */
    public function index($endpoint = null)
    {
        $targetClass = $this->getClass($endpoint);
        if($targetClass instanceof Response){
            return $targetClass;
        }

        $className = $targetClass->gridClass;
        return (new $className($this->plugin, $targetClass))->index();
    }

    /**
     * Show. for detail.
     *
     * @return void
     */
    public function show($endpoint = null, $id = null)
    {
        if(!is_nullorempty($endpoint) && is_nullorempty($id)){
            $id = $endpoint;
            $endpoint = null;
        }

        $targetClass = $this->getClass($endpoint);
        if($targetClass instanceof Response){
            return $targetClass;
        }

        $className = $targetClass->showClass;
        return (new $className($this->plugin, $targetClass))->show($id);
    }

    /**
     * create. 
     *
     * @return void
     */
    public function create($endpoint = null)
    {
        $targetClass = $this->getClass($endpoint);
        if($targetClass instanceof Response){
            return $targetClass;
        }

        $className = $targetClass->createClass;
        return (new $className($this->plugin, $targetClass))->create();
    }


    /**
     * store. 
     *
     * @return void
     */
    public function store($endpoint = null)
    {
        $targetClass = $this->getClass($endpoint);
        if($targetClass instanceof Response){
            return $targetClass;
        }

        $className = $targetClass->createClass;
        return (new $className($this->plugin, $targetClass))->store();
    }


    /**
     * edit. 
     *
     * @return void
     */
    public function edit($endpoint = null, $id = null)
    {
        if(!is_nullorempty($endpoint) && is_nullorempty($id)){
            $id = $endpoint;
            $endpoint = null;
        }

        $targetClass = $this->getClass($endpoint);
        if($targetClass instanceof Response){
            return $targetClass;
        }

        $className = $targetClass->editClass;
        return (new $className($this->plugin, $targetClass))->edit($id);
    }

    /**
     * update. 
     *
     * @return void
     */
    public function update($endpoint = null, $id = null)
    {
        if(!is_nullorempty($endpoint) && is_nullorempty($id)){
            $id = $endpoint;
            $endpoint = null;
        }

        $targetClass = $this->getClass($endpoint);
        if($targetClass instanceof Response){
            return $targetClass;
        }

        $className = $targetClass->editClass;
        return (new $className($this->plugin, $targetClass))->update($id);
    }

    /**
     * destroy. 
     *
     * @return void
     */
    public function destroy($endpoint = null, $id = null)
    {
        if(!is_nullorempty($endpoint) && is_nullorempty($id)){
            $id = $endpoint;
            $endpoint = null;
        }

        $targetClass = $this->getClass($endpoint);
        if($targetClass instanceof Response){
            return $targetClass;
        }

        $className = $targetClass->deleteClass;
        $result = (new $className($this->plugin, $targetClass))->delete($id);

        return getAjaxResponse([
            'status' => true,
            'message' => trans('admin.delete_succeeded'),
            'redirect' => $result,
        ]);
    }

    
    /**
     * Execute login oauth
     *
     * @param Request $request
     * @return void
     */
    public function oauth($endpoint = null)
    {
        $targetClass = $this->getClass($endpoint, false);
        if($targetClass instanceof Response){
            return $targetClass;
        }

        try {
            return $targetClass->getPluginOptions()->loginOAuth();
        } catch (SsoLoginErrorException $ex) {
            //ToDO:修正
            \Log::error($ex);

            // if error, redirect edit page
        } catch (\Exception $ex) {
            //ToDO:修正
            \Log::error($ex);
        }
    }

    /**
     * Execute login oauth callback
     *
     * @param Request $request
     * @return void
     */
    public function oauthcallback($endpoint = null)
    {
        $targetClass = $this->getClass($endpoint, false);
        if($targetClass instanceof Response){
            return $targetClass;
        }

        try {
            $targetClass->getPluginOptions()->setOauthAccessToken();

            // redirect to root
            return redirect($targetClass->getFullUrl());
        } catch (SsoLoginErrorException $ex) {
            //ToDO:修正
            \Log::error($ex);

            // if error, redirect edit page
        } catch (\Exception $ex) {
            //ToDO:修正
            \Log::error($ex);
        }
    }

    /**
     * No Auth page. 
     *
     * @return void
     */
    public function noauth($endpoint = null)
    {
        $targetClass = $this->getClass($endpoint, false);
        
        $authType = $targetClass->getAuthType();
        if(is_nullorempty($authType)){
            abort(500);
        }

        $content = $targetClass->getContent();
        if($authType == PluginCrudAuthType::KEY){
            admin_error('エラーです', ' エラーです。');
        }
        elseif($authType == PluginCrudAuthType::OAUTH){
            $login_provider = $targetClass->getPluginOptions()->getOauthSetting();
            $box = new Box(exmtrans('plugin.error.crud_autherror_auth'), view('exment::auth.plugin_crud_login', [
                'form_providers' => [
                    $login_provider->login_provider_name => $login_provider->getLoginButton(),
                ],
                'formUrl' => $targetClass->getFullUrl('oauth'),
            ]));
            $box->style('danger');

            $content->row($box);
        }

        return $content;
    }

    /**
     * Get plugin target class.
     * *If plugin supports multiple endpoint, get class using endpoint.*
     *
     * @param string|null $endpoint
     * @return PluginCrudBase
     */
    protected function getClass(?string $endpoint, bool $isCheckAuthorize = true)
    {
        $className = $this->pluginPage->getPluginClassName($endpoint);
        if(!$className){
            abort(404);
        }

        $class = new $className($this->plugin);
        $class->setPluginOptions($this->pluginPage->getPluginOptions())
            ->setEndpoint($endpoint);

        if($isCheckAuthorize && ($response = $this->authorizePlugin($endpoint, $class)) instanceof Response){
            return $response;
        }
        
        return $class;
    }

    /**
     * Authorize plugin.
     *
     * @return true|
     */
    protected function authorizePlugin(?string $endpoint, $targetClass)
    {
        $authType = $targetClass->getAuthType();
        if(is_nullorempty($authType)){
            return true;
        }

        if($authType == PluginCrudAuthType::KEY){
            // get key
            $key = $targetClass->getAuthKey();
            if(is_nullorempty($key)){
                return redirect($targetClass->getFullUrl('noauth'));
            }
        }
        elseif($authType == PluginCrudAuthType::OAUTH){
            // get token
            $token = $targetClass->getPluginOptions()->getOauthAccessToken();
            if(is_nullorempty($token)){
                return redirect($targetClass->getFullUrl('noauth'));
            }
        }
    }
}