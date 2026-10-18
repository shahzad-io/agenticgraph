<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings a 0.1.0 executions table to the 0.2.0 shape: stream_id (indexed) and interrupted_at, without the
 * (id, status) index the 0.1.0 "improve" migration added (id is the primary key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agenticgraph_executions', function (Blueprint $table) {
            if (! Schema::hasColumn('agenticgraph_executions', 'stream_id')) {
                $table->string('stream_id')->nullable()->after('status');
                $table->index('stream_id');
            }

            if (! Schema::hasColumn('agenticgraph_executions', 'interrupted_at')) {
                $table->timestamp('interrupted_at')->nullable()->after('started_at');
            }

            if (Schema::hasIndex('agenticgraph_executions', ['id', 'status'])) {
                $table->dropIndex(['id', 'status']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('agenticgraph_executions', function (Blueprint $table) {
            if (Schema::hasColumn('agenticgraph_executions', 'stream_id')) {
                $table->dropIndex(['stream_id']);
                $table->dropColumn('stream_id');
            }

            if (Schema::hasColumn('agenticgraph_executions', 'interrupted_at')) {
                $table->dropColumn('interrupted_at');
            }

            if (! Schema::hasIndex('agenticgraph_executions', ['id', 'status'])) {
                $table->index(['id', 'status']);
            }
        });
    }
};
