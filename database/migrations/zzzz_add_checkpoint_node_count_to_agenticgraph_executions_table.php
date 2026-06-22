<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('agenticgraph_executions', 'checkpoint_node_count')) {
            return;
        }

        Schema::table('agenticgraph_executions', function (Blueprint $table) {
            $table->unsignedInteger('checkpoint_node_count')->default(0)->after('final_state');
        });
    }

    public function down(): void
    {
        Schema::table('agenticgraph_executions', function (Blueprint $table) {
            $table->dropColumn('checkpoint_node_count');
        });
    }
};
