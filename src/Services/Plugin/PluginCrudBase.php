<?php

namespace Exceedone\Exment\Services\Plugin;

use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Encore\Admin\Widgets\Grid\Grid;
use Encore\Admin\Widgets\Form;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Validator\ExmentCustomValidator;

/**
 * Plugin CRUD(and List)
 */
abstract class PluginCrudBase extends PluginPublicBase
{
    use PluginBase;
    use PluginPageTrait;

    public function __construct($plugin, $options = [])
    {
        $this->plugin = $plugin;
        $this->pluginOptions = new PluginOption\PluginOptionCrud($plugin, $this, $options);
    }

    /**
     * content title
     *
     * @var string
     */
    protected $title;

    /**
     * content description
     *
     * @var string
     */
    protected $description;

    /**
     * content icon
     *
     * @var string
     */
    protected $icon;

    /**
     * Crud grid class name. If customize, change class name.
     *
     * @var string
     */
    public $gridClass = PluginCrud\CrudGrid::class;

    /**
     * Crud show class name. If customize, change class name.
     *
     * @var string
     */
    public $showClass = PluginCrud\CrudShow::class;

    /**
     * Crud create class name. If customize, change class name.
     *
     * @var string
     */
    public $createClass = PluginCrud\CrudForm::class;

    /**
     * Crud edit class name. If customize, change class name.
     *
     * @var string
     */
    public $editClass = PluginCrud\CrudForm::class;

    /**
     * Crud delete class name. If customize, change class name.
     *
     * @var string
     */
    public $deleteClass = PluginCrud\CrudForm::class;

    protected $endpoint = null;

    /**
     * Get fields definitions
     *
     * @return array|Collection
     */
    public function getFieldDefinitions()
    {
        return [];
    }

    /**
     * Get data paginate
     *
     * @return LengthAwarePaginator|null
     */
    public function getPaginate(array $options = []): ?LengthAwarePaginator
    {
        return null;
    }

    /**
     * Get data list
     *
     * @return Collection
     */
    public function getList(array $options = []): Collection
    {
        return collect();
    }

    /**
     * Get max chunk count.
     *
     * @return int
     */
    public function getChunkCount(): int
    {
        return 1000;
    }

    /**
     * read single data
     *
     * @return array|Collection
     */
    public function getData($id, array $options = [])
    {
        return collect();
    }

    /**
     * set form info
     *
     * @return Form|null
     */
    public function setForm(Form $form, bool $isCreate, array $options = []): ?Form
    {
        return null;
    }

    /**
     * post create value
     *
     * @return mixed
     */
    public function postCreate(array $posts, array $options = [])
    {
    }

    /**
     * edit posted value
     *
     * @return mixed
     */
    public function putEdit($id, array $posts, array $options = [])
    {
    }

    /**
     * delete value
     *
     * @param $id string
     * @return mixed
     */
    public function delete($id, array $options = [])
    {
    }

    /**
     * delete value
     *
     * @param $ids array
     * @return mixed
     */
    public function deletes(array $ids, array $options = [])
    {
        foreach ($ids as $id) {
            $this->delete($id, $options);
        }
    }

    /**
     * Get the value of endpoint
     */
    public function getEndpoint()
    {
        return $this->endpoint;
    }

    /**
     * Set the value of endpoint
     *
     * @return  self
     */
    public function setEndpoint($endpoint)
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    /**
     * Get target all endpoints
     * If support multiple endpoints, override function, end return.
     *
     * @return Collection|null
     */
    public function getAllEndpoints(): ?Collection
    {
        return null;
    }

    /**
     * Get class name. Toggle using endpoint name.
     *
     * @param string|null $endpoint
     * @param bool $isEmptyEndpoint
     * @return string|null class name
     */
    public function getPluginClassName(?string $endpoint, bool $isEmptyEndpoint)
    {
        if ($isEmptyEndpoint) {
            return get_class($this);
        }

        $allEndpoints = $this->getAllEndpoints();
        if (is_nullorempty($allEndpoints)) {
            return get_class($this);
        }

        foreach ($allEndpoints as $allEndpoint) {
            if ($allEndpoint == $endpoint) {
                return get_class($this);
            }
        }

        return null;
    }

    /**
     * Get auth type.
     * Please set null or "key" pr "id_password" or "oauth".
     *
     * @return string|null
     */
    public function getAuthType(): ?string
    {
        return null;
    }

    /**
     * Get auth setting label.
     *
     * @return string|null
     */
    public function getAuthSettingLabel(): ?string
    {
        return exmtrans('plugin.options.crud_auth_' . $this->getAuthType());
    }

    /**
     * Get auth setting label.
     *
     * @return string|null
     */
    public function getAuthSettingPasswordLabel(): ?string
    {
        return exmtrans('plugin.options.crud_auth_id_password_password');
    }

    /**
     * Get auth setting help.
     *
     * @return string|null
     */
    public function getAuthSettingHelp(): ?string
    {
        return exmtrans('plugin.help.crud_auth_' . $this->getAuthType(), [
            'callback_url' => $this->getFullUrl('oauthcallback')
        ]);
    }

    /**
     * Get auth setting help.
     *
     * @return string|null
     */
    public function getAuthSettingPasswordHelp(): ?string
    {
        return exmtrans('plugin.help.crud_auth_id_password_password');
    }

    /**
     * Get auth for key.
     *
     * @return string|null
     */
    public function getAuthKey(): ?string
    {
        return $this->plugin->getOption('crud_auth_key');
    }

    /**
     * Get auth for id and password.
     *
     * @return array
     */
    public function getAuthIdPassword(): array
    {
        return [
            'id' => $this->plugin->getOption('crud_auth_id'),
            'password' => trydecrypt($this->plugin->getOption('crud_auth_password')),
        ];
    }

    /**
     * Get auth for oauth.
     *
     * @return string|null
     */
    public function getOauthAccessToken(): ?string
    {
        return $this->pluginOptions->getOauthAccessToken();
    }

    public function _plugin()
    {
        return $this->plugin;
    }

    /**
     * Get route uri for page
     *
     * @return string|null
     */
    public function getRouteUri($endpoint = null)
    {
        if (!isset($this->plugin)) {
            return null;
        }

        return $this->plugin->getRouteUri($endpoint);
    }

    /**
     * Get full url
     *
     * @return string
     */
    public function getFullUrl(...$endpoint): string
    {
        array_unshift($endpoint, $this->getEndpoint());
        return $this->plugin->getFullUrl(...$endpoint);
    }

    /**
     * Get Root full url
     * For use oauth login, logout, etc...
     *
     * @return string
     */
    public function getRootFullUrl(...$endpoint): string
    {
        return $this->plugin->getFullUrl(...$endpoint);
    }

    /**
     * Get primary key
     *
     * @return string
     */
    public function getPrimaryKey(): string
    {
        $definitions = $this->getFieldDefinitions();
        return array_get(collect($definitions)->first(function ($definition, $key) {
            return array_boolval($definition, 'primary');
        }), 'key');
    }


    /**
     * Whether use paginate
     * Default: true
     *
     * @return bool
     */
    public function enablePaginate(): bool
    {
        return true;
    }

    /**
     * Whether show data. If false, disable show link.
     * Default: true
     *
     * @return bool
     */
    public function enableShow($value): bool
    {
        return true;
    }

    /**
     * Whether create data. If false, disable create button.
     * Default: true
     *
     * @return bool
     */
    public function enableCreate(array $options = []): bool
    {
        return true;
    }

    /**
     * Whether edit all. If false, disable edit button and link.
     * Default: true
     *
     * @return bool
     */
    public function enableEditAll(array $options = []): bool
    {
        return true;
    }

    /**
     * Whether edit target data. If false, disable edit button and link.
     * Default: true
     *
     * @return bool
     */
    public function enableEdit($value, array $options = []): bool
    {
        return true;
    }

    /**
     * Whether delete all. If false, disable delete button and link.
     * Default: true
     *
     * @return bool
     */
    public function enableDeleteAll(array $options = []): bool
    {
        return true;
    }

    /**
     * Whether delete target data. If false, disable delete button and link.
     * Default: true
     *
     * @return bool
     */
    public function enableDelete($value, array $options = []): bool
    {
        return true;
    }

    /**
     * Whether export data. If false, disable export button and link.
     * Default: false
     *
     * @return bool
     */
    public function enableExport(array $options = []): bool
    {
        return false;
    }

    /**
     * Whether freeword search. If true, show search box in grid.
     * Default: false
     *
     * @return bool
     */
    public function enableFreewordSearch(array $options = []): bool
    {
        return false;
    }

    /**
     * Whether access all CRUD page. If false, cannot access all page.
     * Default: true
     *
     * @return bool
     */
    public function enableAccessCrud(array $options = []): bool
    {
        return true;
    }

    /**
     * Whether show logout button if oauth
     * Default: true
     *
     * @return bool
     */
    public function enableOAuthLogoutButton(array $options = []): bool
    {
        return true;
    }


    /**
     * Get cannot access title
     * @return string
     */
    public function getCannotAccessTitle(array $options = []): ?string
    {
        return exmtrans('plugin.error.crud_autherror_setting');
    }

    /**
     * Get cannot access message
     * @return string
     */
    public function getCannotAccessMessage(array $options = []): ?string
    {
        return exmtrans('plugin.error.crud_autherror_common_help');
    }


    /**
     * Callback grid. If add event, definition.
     *
     * @param Grid $grid
     * @return void
     */
    public function callbackGrid(Grid $grid)
    {
    }


    /**
     * Callback tools. If add event, definition.
     *
     * @param $tools
     * @return void
     */
    public function callbackGridTool($tools)
    {
    }


    /**
     * Callback show page tools. If add event, definition.
     *
     * @param Box $box
     * @return void
     */
    public function callbackShowTool($id, Box $box)
    {
    }

    /**
     * Callback form page tools. If add event, definition.
     *
     * @param Box $box
     * @return void
     */
    public function callbackFormTool($id, Box $box)
    {
    }

    /**
     * Callback grid row action. If add event, definition.
     *
     * @param $actions
     * @return void
     */
    public function callbackGridAction($actions)
    {
    }

    /**
     * Callback show. If add event, definition.
     *
     * @param WidgetForm $form
     * @return void
     */
    public function callbackShow($id, WidgetForm $form, Box $box)
    {
    }

    /**
     * Callback create. If add event, definition.
     *
     * @param WidgetForm $form
     * @return void
     */
    public function callbackCreate(WidgetForm $form, Box $box)
    {
    }

    /**
     * Callback edit. If add event, definition.
     *
     * @param WidgetForm $form
     * @return void
     */
    public function callbackEdit($id, WidgetForm $form, Box $box)
    {
    }

    /**
     * Set column difinition for grid. If add event, definition.
     *
     * @param Grid $grid
     * @return void
     */
    public function setGridColumnDifinition(Grid $grid, string $key, string $label)
    {
        $grid->column($key, $label);
    }

    /**
     * Set column difinition for show. If add event, definition.
     *
     * @param WidgetForm $form
     * @return void
     */
    public function setShowColumnDifinition(WidgetForm $form, string $key, string $label)
    {
        $form->display($key, $label);
    }

    /**
     * Set column difinition for create. If add event, definition.
     *
     * @param WidgetForm $form
     * @return void
     */
    public function setCreateColumnDifinition(WidgetForm $form, string $key, string $label)
    {
        $form->text($key, $label);
    }

    /**
     * Set column difinition for edit. If add event, definition.
     *
     * @param WidgetForm $form
     * @return void
     */
    public function setEditColumnDifinition(WidgetForm $form, string $key, string $label)
    {
        $form->text($key, $label);
    }

    /**
     * Set column difinition for form's primary key. If add event, definition.
     *
     * @param WidgetForm $form
     * @return void
     */
    public function setFormPrimaryDifinition(WidgetForm $form, string $key, string $label)
    {
        $form->display($key, $label);
    }

    /**
     * Validate form
     *
     * @param WidgetForm $form
     * @param array $values
     * @param bool $isCreate
     * @param $id
     * @return bool|\Illuminate\Support\MessageBag
     */
    public function validate(WidgetForm $form, array $values, bool $isCreate, $id)
    {
        return $form->validationMessageArray($values);
    }

    /**
     * Get content
     *
     * @return Content
     */
    public function getContent(): Content
    {
        $content = new Content();
        if (!is_nullorempty($title = $this->plugin->getOption('title', $this->getTitle()))) {
            $content->header($title);
        }
        if (!is_nullorempty($description = $this->plugin->getOption('description', $this->getDescription()))) {
            $content->description($description);
        }
        if (!is_nullorempty($headericon = $this->plugin->getOption('icon', $this->getIcon()))) {
            $content->headericon($headericon);
        }

        return $content;
    }

    /**
     * Get content title
     *
     * @return  string
     */
    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * Get content description
     *
     * @return  string
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Get content icon
     *
     * @return  string
     */
    public function getIcon(): ?string
    {
        return $this->icon;
    }
}
