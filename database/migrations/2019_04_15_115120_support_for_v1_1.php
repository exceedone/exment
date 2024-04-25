<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Database\ExtendedBlueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Enums\CurrencySymbol;

class SupportForV11 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->blueprintResolver(function ($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

        if (!Schema::hasTable('custom_view_summaries')) {
            $schema->create('custom_view_summaries', function (ExtendedBlueprint $table) {
                $table->increments('id');
                $table->integer('custom_view_id')->unsigned();
                $table->integer('view_column_type')->default(0);
                $table->integer('view_column_target_id')->nullable();
                $table->integer('view_summary_condition')->unsigned()->default(0);
                $table->string('view_column_name', 40)->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->timeusers();

                $table->foreign('custom_view_id')->references('id')->on('custom_views');
            });
        }

        if (!Schema::hasColumn('custom_view_columns', 'view_column_name')) {
            $schema->table('custom_view_columns', function ($table) {
                $table->string('view_column_name', 40)->nullable();
            });
        }

        if (!Schema::hasColumn('custom_view_columns', 'view_column_table_id')) {
            Schema::table('custom_view_columns', function (Blueprint $table) {
                $table->integer('view_column_table_id')->after('view_column_type')->unsigned();
            });
        }

        if (!Schema::hasColumn('custom_view_summaries', 'view_column_table_id')) {
            Schema::table('custom_view_summaries', function (Blueprint $table) {
                $table->integer('view_column_table_id')->after('view_column_type')->unsigned();
            });
        }

        if (!Schema::hasColumn('custom_view_filters', 'view_column_table_id')) {
            Schema::table('custom_view_filters', function (Blueprint $table) {
                $table->integer('view_column_table_id')->after('view_column_type')->unsigned();
            });
        }

        if (!Schema::hasColumn('custom_view_sorts', 'view_column_table_id')) {
            Schema::table('custom_view_sorts', function (Blueprint $table) {
                $table->integer('view_column_table_id')->after('view_column_type')->unsigned();
            });
        }

        if (!Schema::hasColumn('custom_copy_columns', 'from_column_table_id')) {
            Schema::table('custom_copy_columns', function (Blueprint $table) {
                $table->integer('from_column_table_id')->nullable()->after('from_column_type')->unsigned();
                $table->integer('to_column_table_id')->after('to_column_type')->unsigned();
            });
        }

        ///// set default value.
        $updateClasses = [
            Model\CustomViewColumn::class,
            Model\CustomViewSummary::class,
            Model\CustomViewFilter::class,
            Model\CustomViewSort::class,
        ];
        foreach ($updateClasses as $updateClass) {
            $results = $updateClass::with(['custom_column', 'custom_view'])
            ->get();

            /** @var Model\CustomViewColumn|Model\CustomViewSummary|Model\CustomViewFilter|Model\CustomViewSort $result */
            foreach ($results as $result) {
                if (array_get($result, 'view_column_type') == 0) {
                    $result->view_column_table_id = array_get($result, 'custom_column.custom_table_id');
                } else {
                    $result->view_column_table_id = array_get($result, 'custom_view.custom_table_id');
                }
                $result->save();
            }
        }

        $results = Model\CustomCopyColumn::with(['from_custom_column', 'to_custom_column', 'custom_copy'])
            ->get();

        foreach ($results as $result) {
            if (array_get($result, 'from_column_type') == 0) {
                $result->from_column_table_id = array_get($result, 'from_custom_column.custom_table_id');
            } else {
                $result->from_column_table_id = array_get($result, 'custom_copy.custom_table_id');
            }

            if (array_get($result, 'to_column_type') == 0) {
                $result->to_column_table_id = array_get($result, 'to_custom_column.custom_table_id');
            } else {
                $result->to_column_table_id = array_get($result, 'custom_copy.custom_table_id');
            }

            $result->save();
        }

        // drop table name unique index from custom table
        if (count(Schema::getUniqueDefinitions('custom_tables', 'table_name')) > 0) {
            Schema::table('custom_tables', function (Blueprint $table) {
                $table->dropUnique(['table_name']);
            });
        }

        // add order column to custom_tables and custom_columns
        if (!Schema::hasColumn('custom_tables', 'order')) {
            Schema::table('custom_tables', function (Blueprint $table) {
                $table->integer('order')->after('showlist_flg')->default(0);
            });
        }

        if (!Schema::hasColumn('custom_columns', 'order')) {
            Schema::table('custom_columns', function (Blueprint $table) {
                $table->integer('order')->after('system_flg')->default(0);
            });
        }

        // Change Custom Column options.currency_symbol
        $columns = CustomColumn::whereNotNull('options->currency_symbol')->get();
        foreach ($columns as $column) {
            // update options->currency_symbol
            $symbol = CurrencySymbol::getEnum($column->getOption('currency_symbol'));
            if (!isset($symbol)) {
                continue;
            }

            $column->setOption('currency_symbol', $symbol->getKey());
            $column->save();
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->blueprintResolver(function ($table, $callback) {
            return new ExtendedBlueprint($table, $callback);
        });

        $schema->table('custom_view_columns', function (ExtendedBlueprint $table) {
            $table->dropColumn('view_column_table_id');
        });
        $schema->table('custom_view_summaries', function (ExtendedBlueprint $table) {
            $table->dropColumn('view_column_table_id');
        });
        $schema->table('custom_view_filters', function (ExtendedBlueprint $table) {
            $table->dropColumn('view_column_table_id');
        });
        $schema->table('custom_view_sorts', function (ExtendedBlueprint $table) {
            $table->dropColumn('view_column_table_id');
        });
        // add table name unique index from custom table
        // Schema::table('custom_tables', function (Blueprint $table) {
        //     $table->unique(['table_name']);
        // });

        Schema::dropIfExists('custom_view_summaries');

        $schema->table('custom_view_columns', function ($table) {
            $table->dropColumn('view_column_name');
        });
    }
}
