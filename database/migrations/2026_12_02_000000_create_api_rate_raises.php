<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The temporary raises of the API rate limit (ISS00442). The ones past their time stay
     * listed but are not used.
     */
    public function up(): void
    {
        if (!Schema::hasTable('api_rate_raises')) {
            Schema::create('api_rate_raises', function (Blueprint $table) {
                $table->bigIncrements('id');
                // "user" (the id of the user) or "client" (the id of the API client)
                $table->string('target_type', 16);
                $table->string('target_id', 100);
                $table->unsignedInteger('per_minute');
                $table->dateTime('expires_at');
                $table->string('reason', 1000)->nullable();
                $table->unsignedBigInteger('created_user_id')->nullable();
                $table->timestamps();
                $table->index('expires_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_rate_raises');
    }
};
