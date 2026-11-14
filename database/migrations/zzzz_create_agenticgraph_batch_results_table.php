<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch results of an async fan-out, kept until the batch ends (one row per branch, plus the fan-out input).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agenticgraph_batch_results')) {
            return;
        }

        Schema::create('agenticgraph_batch_results', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('execution_id');
            $table->string('node_name');
            $table->longText('state');
            $table->timestamp('created_at')->nullable();

            $table->unique(['execution_id', 'node_name']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agenticgraph_batch_results');
    }
};
