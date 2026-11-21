<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('diagnostic_findings', function (Blueprint $table) use ($driver): void {
            // Avoid constrained() on sqlite: FK addition rebuilds the table and breaks
            // tenant-guard triggers that reference diagnostic_findings mid-rename.
            if ($driver === 'sqlite') {
                $table->unsignedBigInteger('assignee_user_id')->nullable();
            } else {
                $table->foreignId('assignee_user_id')
                    ->nullable()
                    ->after('status')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            $table->timestamp('status_updated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('status_note', 500)->nullable();
            $table->index(['tenant_id', 'assignee_user_id'], 'diagnostic_findings_tenant_assignee_idx');
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('diagnostic_findings', function (Blueprint $table) use ($driver): void {
            $table->dropIndex('diagnostic_findings_tenant_assignee_idx');

            if ($driver === 'sqlite') {
                $table->dropColumn(['assignee_user_id', 'status_updated_at', 'resolved_at', 'status_note']);
            } else {
                $table->dropConstrainedForeignId('assignee_user_id');
                $table->dropColumn(['status_updated_at', 'resolved_at', 'status_note']);
            }
        });
    }
};
