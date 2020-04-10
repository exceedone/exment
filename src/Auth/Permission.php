<?php
namespace Exceedone\Exment\Auth;

use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\Permission as PermissionEnum;

class Permission
{
    /**
     * Summary of $role_type
     * @var string
     */
    protected $role_type;

    /**
     * Summary of $table_name
     * @var string
     */
    protected $table_name;

    /**
     * Summary of $permission_details
     * @var array
     */
    protected $permission_details;

    /**
     * $shouldPass custom
     * @var \Closure[]
     */
    protected static $bootingShouldPasses = [];

    /**
     * Create a new Eloquent model instance.
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->role_type = array_get($attributes, 'role_type');
        $this->table_name = array_get($attributes, 'table_name');
        $this->permission_details = array_get($attributes, 'permission_details');
    }

    public function getRoleType()
    {
        return $this->role_type;
    }

    public function getTableName()
    {
        return $this->table_name;
    }

    public function getPermissionDetails()
    {
        return $this->permission_details;
    }

    /**
     * @param callable $callback
     */
    public static function bootingShouldPass(callable $callback)
    {
        static::$bootingShouldPasses[] = $callback;
    }

    /**
     * Call the booting ShouldPasses for the exment application.
     */
    protected function fireShouldPasses($endpoint)
    {
        foreach (static::$bootingShouldPasses as $callable) {
            $result = call_user_func($callable, $endpoint);

            if ($result === true || $result === false) {
                return $result;
            }
        }
    }

    /**
     * If request should pass through the current permission.
     *
     * @param Request $request
     *
     * @return bool
     */
    public function shouldPassThrough(Request $request) : bool
    {
        // get target endpoint
        $endpoint = $this->getEndPoint($request);

        return $this->shouldPass($endpoint);
    }

    /**
     * If request should pass through the current permission.
     *
     * @param string|null $endpoint
     *
     * @return bool
     */
    public function shouldPass($endpoint) : bool
    {
        // checking booting function
        $result = $this->fireShouldPasses($endpoint);
        if ($result === true || $result === false) {
            return $result;
        }

        // if 'role' or 'role_group' and !System::permission_available(), false
        if (in_array($endpoint, ['role', 'role_group']) && !System::permission_available()) {
            return false;
        }
        // if api setting, check config
        if (in_array($endpoint, ['api_setting']) && !System::api_available()) {
            return false;
        }

        // if system doesn't use role, return true
        if (!System::permission_available()) {
            return true;
        }

        // not admin page's (for custom url), return true
        $parse_url = parse_url($endpoint);
        if ($parse_url && array_has($parse_url, 'host') && strpos($endpoint, admin_url()) === false) {
            return true;
        }

        // if system user, return true
        $systemRole = RoleType::SYSTEM == $this->role_type;
        if ($systemRole && array_key_exists('system', $this->permission_details)) {
            return true;
        }

        switch ($endpoint) {
            case "":
            case "/":
            case "index":
            case "api":
            case "search":
            case "auth-2factor":
            case "auth/login":
            case "auth/logout":
            case "saml/login":
            case "saml/logout":
            case "auth/setting":
            case "auth/change":
            case "dashboard":
            case "dashboardbox":
            case "oauth":
            case "files":
            case "notify_navbar":
                return true;
            ///// only system permission
            case "system":
            case "plugin":
            case "login_setting":
            case "database":
            case "auth/menu":
            case "auth/logs":
                if ($systemRole) {
                    return array_key_exists('system', $this->permission_details);
                }
                return false;
            ///// each permissions
            case "notify":
                return \Exment::user()->hasPermissionContainsTable(PermissionEnum::CUSTOM_TABLE);
            case "loginuser":
                if ($systemRole) {
                    return array_key_exists(PermissionEnum::LOGIN_USER, $this->permission_details);
                }
                return false;
            case "api_setting":
                if ($systemRole) {
                    return array_keys_exists(PermissionEnum::AVAILABLE_API, $this->permission_details);
                }
                return false;
            case "workflow":
                if ($systemRole) {
                    return array_keys_exists(PermissionEnum::WORKFLOW, $this->permission_details);
                }
                return false;
            case "role":
            case "role_group":
                if ($systemRole) {
                    return array_keys_exists(PermissionEnum::AVAILABLE_ACCESS_ROLE_GROUP, $this->permission_details);
                }
                return false;
            case "table":
                if ($systemRole) {
                    return array_key_exists('custom_table', $this->permission_details);
                }
                return array_key_exists('custom_table', $this->permission_details);
            case "column":
                if ($systemRole) {
                    return array_key_exists('custom_table', $this->permission_details);
                }
                // check endpoint name and checking table_name.
                if (!$this->matchEndPointTable(null)) {
                    return false;
                }
                return array_key_exists('custom_table', $this->permission_details);
            case "relation":
                if ($systemRole) {
                    return array_key_exists('custom_table', $this->permission_details);
                }
                // check endpoint name and checking table_name.
                if (!$this->matchEndPointTable(null)) {
                    return false;
                }
                return array_key_exists('custom_table', $this->permission_details);
            case "form":
                if ($systemRole) {
                    return array_key_exists('custom_form', $this->permission_details);
                }
                // check endpoint name and checking table_name.
                if (!$this->matchEndPointTable(null)) {
                    return false;
                }
                return array_key_exists('custom_form', $this->permission_details);
            case "view":
                if ($systemRole) {
                    return array_keys_exists(PermissionEnum::AVAILABLE_VIEW_CUSTOM_VALUE, $this->permission_details);
                }
                // check endpoint name and checking table_name.
                if (!$this->matchEndPointTable(null)) {
                    return false;
                }
                return array_keys_exists(PermissionEnum::AVAILABLE_VIEW_CUSTOM_VALUE, $this->permission_details);
            case "data":
                return $this->validateCustomValuePermission($systemRole, $endpoint);
        }
        // if find endpoint "data/", check as data
        if (strpos($endpoint, 'data/') !== false) {
            return $this->validateCustomValuePermission($systemRole, preg_replace('/^data\//', '', $endpoint));
        }

        return false;
    }

    /**
     * Get Endpoint
     * @param Request $request
     * @return mixed
     */
    protected function getEndPoint(Request $request)
    {
        // remove admin url from request url.
        $url = str_replace(admin_url(), '', $request->url());
        
        ///// get last url.
        $uris = explode("/", $url);
        foreach ($uris as $k => $uri) {
            if (!isset($uri) || mb_strlen($uri) == 0) {
                continue;
            }

            // if $uri is "auth", get next uri.
            if (in_array($uri, ['data', 'auth'])) {
                // but url is last item, return $uri.
                if (count($uris) <= $k+1) {
                    return $uri;
                }
                // return $uri adding next item.
                return url_join($uri, $uris[$k+1]);
            } else {
                return $uri;
            }
        }
        return "";
    }

    /**
     * Whether matching url endpoint and table_name for check.
     * @param Request $request
     * @return mixed
     */
    protected function matchEndPointTable($endpoint)
    {
        // Get Endpoint
        $table = CustomTable::findByEndpoint($endpoint);
        if (!isset($table)) {
            return false;
        }
        // check endpoint name and checking table_name.
        return $this->table_name == $table->table_name;
    }

    /**
     * Check custom value's permission
     *
     * @return void
     */
    protected function validateCustomValuePermission($systemRole, $endpoint)
    {
        // if request has id, permission contains CUSTOM_VALUE_ACCESS
        if (!is_null($id = request()->id) && request()->is(trim(admin_base_path("data/$endpoint/*"), '/'))) {
            $permissions = PermissionEnum::AVAILABLE_ACCESS_CUSTOM_VALUE;
        } else {
            $permissions = PermissionEnum::AVAILABLE_VIEW_CUSTOM_VALUE;
        }

        if ($systemRole) {
            return array_keys_exists($permissions, $this->permission_details);
        }
        // check endpoint name and checking table_name.
        if (!$this->matchEndPointTable($endpoint)) {
            return false;
        }
        return array_keys_exists($permissions, $this->permission_details);
    }
}
