<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

class TenantMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        // Lấy subdomain từ host
        $subdomain = explode('.', $request->getHost())[0];

        // Tìm tenant dựa vào subdomain
        $tenant = Tenant::where('subdomain', $subdomain)->firstOrFail();

        // Set cấu hình DB động
        $this->setTenantConnection($tenant);

        return $next($request);
    }

    /**
     * Set tenant database connection
     */
    protected function setTenantConnection(Tenant $tenant)
    {
        // giả sử bảng tenants có các field: db_host, db_port, db_name, db_username, db_password
        $connectionName = 'tenant'; // connection động

        // clone config từ mysql mặc định
        $default = Config::get("database.connections.mysql");

        Config::set("database.connections.$connectionName", array_merge($default, [
            'host'     => $tenant->db_host,
            'port'     => $tenant->db_port,
            'database' => $tenant->db_name,
            'username' => $tenant->db_username,
            'password' => $tenant->db_password,
        ]));

        // đặt connection mặc định sang tenant
        DB::setDefaultConnection($connectionName);
    }
}
