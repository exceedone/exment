<?php
namespace Exceedone\Exment\Auth;

use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\RoleValue;

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
        // if system doesn't use role, return true
        if (!System::permission_available()) {
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
            case "auth/login":
            case "auth/logout":
            case "auth/setting":
            case "dashboard":
            case "dashboardbox":
            case "files":
                return true;
            case "system":
            case "role":
            case "plugin":
            case "database":
            case "loginuser":
            case "auth/menu":
            case "notify":
                if ($systemRole) {
                    return array_key_exists('system', $this->permission_details);
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
                    return array_keys_exists([RoleValue::CUSTOM_VIEW], $this->permission_details);
                }
                // check endpoint name and checking table_name.
                if (!$this->matchEndPointTable()) {
                    return false;
                }
                return array_keys_exists([RoleValue::CUSTOM_VIEW], $this->permission_details);
            case "data":
                if ($systemRole) {
                    return array_keys_exists(RoleValue::AVAILABLE_VIEW_CUSTOM_VALUE, $this->permission_details);
                }
                // check endpoint name and checking table_name.
                if (!$this->matchEndPointTable($endpoint)) {
                    return false;
                }
                return array_keys_exists(RoleValue::AVAILABLE_VIEW_CUSTOM_VALUE, $this->permission_details);
        }
        // if find endpoint "data/", check as data
        if (strpos($endpoint, 'data/') !== false) {
            if ($systemRole) {
                return array_keys_exists(RoleValue::AVAILABLE_VIEW_CUSTOM_VALUE, $this->permission_details);
            }
            // check endpoint name and checking table_name.
            if (!$this->matchEndPointTable($endpoint)) {
                return false;
            }
            return array_keys_exists(RoleValue::AVAILABLE_VIEW_CUSTOM_VALUE, $this->permission_details);
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
}
