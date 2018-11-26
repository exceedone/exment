<?php
namespace Exceedone\Exment\Auth;

use Illuminate\Http\Request;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\AuthorityType;

class Permission
{
    /**
     * Summary of $table_name
     * @var string
     */
    protected $authority_type;

    /**
     * Summary of $table_name
     * @var string
     */
    protected $table_name;

    /**
     * Summary of $authorities
     * @var array
     */
    protected $authorities;
    /**
     * Create a new Eloquent model instance.
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->authority_type = array_get($attributes, 'authority_type');
        $this->table_name = array_get($attributes, 'table_name');
        $this->authorities = array_get($attributes, 'authorities');
    }

    public function getAuthorityType()
    {
        return $this->authority_type;
    }

    public function getTableName()
    {
        return $this->table_name;
    }

    public function getAuthorities()
    {
        return $this->authorities;
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
        // if system doesn't use authority, return true
        if (!System::authority_available()) {
            return true;
        }

        $systemAuthority = AuthorityType::SYSTEM()->match($this->authority_type);
        if ($systemAuthority && array_key_exists(AuthorityType::SYSTEM()->toString(), $this->authorities)) {
            return true;
        }
        switch ($endpoint) {
            case "":
            case "/":
            case "index":
            case "api":
            case "auth/login":
            case "auth/logout":
            case "auth/setting":
            case "dashboard":
            case "dashboardbox":
            case "files":
                return true;
            case "system":
            case "authority":
            case "plugin":
            case "loginuser":
            case "auth/menu":
            case "notify":
                if ($systemAuthority) {
                    return array_key_exists('system', $this->authorities);
                }
                return false;
            case "table":
                if ($systemAuthority) {
                    return array_key_exists('custom_table', $this->authorities);
                }
                return array_key_exists('custom_table', $this->authorities);
            case "column":
                if ($systemAuthority) {
                    return array_key_exists('custom_table', $this->authorities);
                }
                // check endpoint name and checking table_name.
                if (!$this->matchEndPointTable(null)) {
                    return false;
                }
                return array_key_exists('custom_table', $this->authorities);
            case "relation":
                if ($systemAuthority) {
                    return array_key_exists('custom_table', $this->authorities);
                }
                // check endpoint name and checking table_name.
                if (!$this->matchEndPointTable(null)) {
                    return false;
                }
                return array_key_exists('custom_table', $this->authorities);
            case "form":
                if ($systemAuthority) {
                    return array_key_exists('custom_form', $this->authorities);
                }
                // check endpoint name and checking table_name.
                if (!$this->matchEndPointTable(null)) {
                    return false;
                }
                return array_key_exists('custom_form', $this->authorities);
            case "view":
                if ($systemAuthority) {
                    return array_keys_exists([Define::AUTHORITY_VALUE_CUSTOM_VIEW], $this->authorities);
                }
                // check endpoint name and checking table_name.
                if (!$this->matchEndPointTable()) {
                    return false;
                }
               return array_keys_exists([Define::AUTHORITY_VALUE_CUSTOM_VIEW], $this->authorities);
            case "data":
                if ($systemAuthority) {
                    return array_key_exists(Define::AUTHORITY_VALUE_CUSTOM_VALUE_EDIT_ALL, $this->authorities);
                }
                // check endpoint name and checking table_name.
                if (!$this->matchEndPointTable($endpoint)) {
                    return false;
                }
                return array_keys_exists(Define::AUTHORITY_VALUES_AVAILABLE_ACCESS_CUSTOM_VALUE, $this->authorities);
        }
        // if find endpoint "data/", check as data
        if (strpos($endpoint, 'data/') !== false) {
            if ($systemAuthority) {
                return array_key_exists(Define::AUTHORITY_VALUE_CUSTOM_VALUE_EDIT_ALL, $this->authorities);
            }
            // check endpoint name and checking table_name.
            if (!$this->matchEndPointTable($endpoint)) {
                return false;
            }
            return array_keys_exists(Define::AUTHORITY_VALUES_AVAILABLE_ACCESS_CUSTOM_VALUE, $this->authorities);
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
        $table = getEndpointTable($endpoint);
        if (!isset($table)) {
            return false;
        }
        // check endpoint name and checking table_name.
        return $this->table_name == $table->table_name;
    }
}
