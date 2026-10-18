<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('agenticgraph_executions', 'execution_options')) {
            return;
        }

        Schema::table('agenticgraph_executions', function (Blueprint $table) {
            $table->json('execution_options')->nullable()->after('checkpoint_node_count');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('agenticgraph_executions', 'execution_options')) {
            return;
        }

        Schema::table('agenticgraph_executions', function (Blueprint $table) {
            $table->dropColumn('execution_options');
        });
    }
};
