<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agenticgraph_checkpoints')) {
            return;
        }

        Schema::table('agenticgraph_checkpoints', function (Blueprint $table) {
            if (Schema::hasColumn('agenticgraph_checkpoints', 'parent_checkpoint_id')) {
                $table->dropForeign(['parent_checkpoint_id']);
            }
        });

        Schema::table('agenticgraph_checkpoints', function (Blueprint $table) {
            $columnsToDrop = array_values(array_filter(
                ['completed_nodes', 'pending_nodes', 'parent_checkpoint_id'],
                fn (string $column): bool => Schema::hasColumn('agenticgraph_checkpoints', $column)
            ));

            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('agenticgraph_checkpoints')) {
            return;
        }

        Schema::table('agenticgraph_checkpoints', function (Blueprint $table) {
            if (! Schema::hasColumn('agenticgraph_checkpoints', 'completed_nodes')) {
                $table->json('completed_nodes')->nullable();
            }
            if (! Schema::hasColumn('agenticgraph_checkpoints', 'pending_nodes')) {
                $table->json('pending_nodes')->nullable();
            }
            if (! Schema::hasColumn('agenticgraph_checkpoints', 'parent_checkpoint_id')) {
                $table->string('parent_checkpoint_id')->nullable();
            }
        });

        Schema::table('agenticgraph_checkpoints', function (Blueprint $table) {
            if (Schema::hasColumn('agenticgraph_checkpoints', 'parent_checkpoint_id')) {
                $table->foreign('parent_checkpoint_id')
                    ->references('id')
                    ->on('agenticgraph_checkpoints')
                    ->nullOnDelete();
            }
        });
    }
};
