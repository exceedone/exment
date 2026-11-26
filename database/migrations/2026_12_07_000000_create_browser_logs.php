<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The log of the browsers (ISS02904): the errors of the scripts, the requests that failed and
     * (when it is chosen) the pages opened, for the questions of the users. Never a value typed,
     * a text of the page nor a body. Kept as long as the log management says (LogCatalog).
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('browser_logs')) {
            return;
        }
        Schema::create('browser_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('base_user_id')->unsigned()->nullable();
            // error, request, page
            $table->string('kind', 16);
            // the page or the request: the path, the values of its query hidden
            $table->string('path', 255)->nullable();
            // the HTTP status of a request (0: no answer)
            $table->smallInteger('status')->unsigned()->nullable();
            $table->integer('duration_ms')->unsigned()->nullable();
            // the message of an error (hidden as the application log hides it)
            $table->string('message', 500)->nullable();
            // where: the script and its line, the first lines of the stack
            $table->string('source', 255)->nullable();
            // the same error again on the page
            $table->integer('count')->unsigned()->default(1);
            // a mark of the tab of the browser (random): the rows of one tab
            $table->string('tab', 16)->nullable();
            $table->string('browser', 50)->nullable();
            $table->string('os', 50)->nullable();
            $table->string('screen', 20)->nullable();
            $table->string('lang', 20)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['base_user_id', 'created_at']);
            $table->index(['kind', 'created_at']);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('browser_logs');
    }
};
