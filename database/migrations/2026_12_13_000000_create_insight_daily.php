<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The numbers of a day for the page "Insights" (ISS02964): a metric, a key (a table, a result,
     * a tool... or empty) and a value. Made every night from the logs (exment:insights); the
     * page reads only these rows (and today, when it is cheap).
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('insight_daily')) {
            return;
        }
        Schema::create('insight_daily', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('date');
            $table->string('metric', 40);
            // empty when the metric has no key
            $table->string('key', 191)->default('');
            $table->bigInteger('value')->default(0);
            $table->unique(['date', 'metric', 'key']);
            $table->index(['metric', 'date']);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('insight_daily');
    }
};
