<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safe_action_executions', function (Blueprint $table): void {
            $table->string('issue_key', 64)->nullable()->after('finding_id');
            $table->string('mode', 16)->default('one_click')->after('action_key');
            $table->uuid('batch_id')->nullable()->after('mode');
            $table->unsignedInteger('items_total')->nullable()->after('batch_id');
            $table->unsignedInteger('items_succeeded')->nullable()->after('items_total');
            $table->unsignedInteger('minutes_saved')->nullable()->after('items_succeeded');
            $table->jsonb('preview')->nullable()->after('input');
            $table->jsonb('target_ids')->nullable()->after('preview');

            $table->index('batch_id', 'safe_action_executions_batch_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('safe_action_executions', function (Blueprint $table): void {
            $table->dropIndex('safe_action_executions_batch_id_index');
            $table->dropColumn([
                'issue_key',
                'mode',
                'batch_id',
                'items_total',
                'items_succeeded',
                'minutes_saved',
                'preview',
                'target_ids',
            ]);
        });
    }
};
