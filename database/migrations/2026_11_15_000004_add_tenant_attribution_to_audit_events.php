<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->dropSqliteAppendOnlyTriggers();
        }

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->foreignUuid('tenant_id')->nullable()->after('actor_id')
                ->constrained('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'chain_sequence']);
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->restoreSqliteAppendOnlyTriggers();
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->dropSqliteAppendOnlyTriggers();
        }

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'chain_sequence']);
            $table->dropConstrainedForeignId('tenant_id');
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->restoreSqliteAppendOnlyTriggers();
        }
    }

    private function dropSqliteAppendOnlyTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_delete');
    }

    private function restoreSqliteAppendOnlyTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_events_reject_update
            BEFORE UPDATE ON audit_events
            BEGIN
                SELECT RAISE(ABORT, 'audit_events is append-only; UPDATE is prohibited');
            END;
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_events_reject_delete
            BEFORE DELETE ON audit_events
            BEGIN
                SELECT RAISE(ABORT, 'audit_events is append-only; DELETE is prohibited');
            END;
        SQL);
    }
};
