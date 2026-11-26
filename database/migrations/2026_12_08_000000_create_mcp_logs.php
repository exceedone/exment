<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The log of the MCP calls (ISS02915): the tool, the user, the time, how long, how big the
     * answer was and why a call was refused. The arguments as the operation log keeps them (the
     * secrets masked; the keys only when the input is not kept). Kept as long as the log
     * management says (LogCatalog).
     *
     * @return void
     */
    public function up()
    {
        $schema = Schema::connection(\Exceedone\Exment\Services\LogManagement\LogConnection::name());
        if ($schema->hasTable('mcp_logs')) {
            return;
        }
        $schema->create('mcp_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('base_user_id')->unsigned()->nullable();
            // the JSON-RPC method: initialize, tools/list, tools/call, ...
            $table->string('method', 50)->nullable();
            // the tool of tools/call
            $table->string('tool', 100)->nullable();
            // ok, tool_error (the tool answered isError), error (a JSON-RPC error), refused
            $table->string('status', 16);
            // why: the error or the refusal (rate_limited, parse_error, ...), shortened
            $table->string('reason', 255)->nullable();
            $table->integer('rpc_code')->nullable();
            $table->integer('duration_ms')->unsigned()->nullable();
            // the bytes of the answer
            $table->integer('result_bytes')->unsigned()->nullable();
            // the arguments, as the operation log keeps them
            $table->string('args', 1000)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['base_user_id', 'created_at']);
            $table->index(['tool', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::connection(\Exceedone\Exment\Services\LogManagement\LogConnection::name())->dropIfExists('mcp_logs');
    }
};
