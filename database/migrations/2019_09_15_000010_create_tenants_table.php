<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Exceedone\Exment\Enums\TenantStatus;
use Exceedone\Exment\Enums\TenantType;

class CreateTenantsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            
            // Tenant SUUID for unique identification
            $table->string('tenant_suuid', 255);
            
            // Subdomain for tenant identification (e.g., mycompany.exment.org)
            $table->string('subdomain', 63)->nullable();

            $table->string('new_subdomain', 63)->nullable();
            
            // Tenant path for path-based identification (e.g., exment.org/mycompany)
            $table->string('tenant_path', 100)->nullable();
            
            // Type to distinguish between subdomain and tenant_path
            $table->enum('type', TenantType::arrays())->default(TenantType::SUBDOMAIN);
            
            // Plan information and configuration
            $table->json('plan_info')->nullable();
            
            // Tenant status
            $table->enum('status', TenantStatus::arrays())->default(TenantStatus::PENDING);
            
            // Environment settings (language, timezone, etc.)
            $table->json('environment_settings')->nullable();
            
            // Token for authentication
            $table->text('token')->nullable();
            
            // Timestamps
            $table->timestamps();
            
            $table->json('data')->nullable();
            
            // Soft deletes
            $table->softDeletes();
            
            // Indexes for better performance
            $table->index(['type', 'status'], 'tenants_type_status_index');
            $table->index(['type', 'tenant_path'], 'tenants_type_path_index');
            $table->index(['status', 'created_at'], 'tenants_status_created_index');
            $table->index(['tenant_suuid'], 'tenants_suuid_index');
            $table->index(['new_subdomain'], 'tenants_new_subdomain_index');
            
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('tenants');
    }
}
