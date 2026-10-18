<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agenticgraph_checkpoints', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('graph_name');
            $table->string('execution_id');
            $table->string('current_node');
            $table->json('state');
            $table->json('completed_nodes')->nullable();
            $table->json('pending_nodes')->nullable();
            $table->json('metadata')->nullable();
            $table->string('parent_checkpoint_id')->nullable();
            $table->timestamp('created_at');

            // Add critical indexes for performance
            $table->index('execution_id'); // For loading checkpoints by execution
            $table->index(['graph_name', 'created_at']); // For list() queries
            $table->index('created_at'); // For cleanup() queries

            // Add foreign key constraints for referential integrity
            $table->foreign('execution_id')
                ->references('id')
                ->on('agenticgraph_executions')
                ->onDelete('cascade'); // Delete checkpoints when execution is deleted

            $table->foreign('parent_checkpoint_id')
                ->references('id')
                ->on('agenticgraph_checkpoints')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agenticgraph_checkpoints');
    }
};
