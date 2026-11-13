<?php

use App\Services\Audit\AuditEventCanonicalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('chain_sequence')->nullable();
            $table->string('previous_hash', 64)->nullable();
            $table->string('event_hash', 64)->nullable();
            $table->unsignedSmallInteger('hash_version')->nullable();
        });

        Schema::create('audit_chain_heads', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('chain_sequence')->default(0);
            $table->string('head_hash', 64);
            $table->timestamps();
        });

        DB::table('audit_chain_heads')->insert([
            'id' => 1,
            'chain_sequence' => 0,
            'head_hash' => AuditEventCanonicalizer::GENESIS_HASH,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->suspendAppendOnlyTrigger();

        try {
            $canonicalizer = app(AuditEventCanonicalizer::class);
            $sequence = 0;
            $previousHash = AuditEventCanonicalizer::GENESIS_HASH;

            DB::table('audit_events')->orderBy('id')->chunk(500, function ($rows) use ($canonicalizer, &$sequence, &$previousHash): void {
                foreach ($rows as $row) {
                    $sequence++;
                    $event = [
                        'chain_sequence' => $sequence,
                        'previous_hash' => $previousHash,
                        'actor_type' => $row->actor_type,
                        'actor_id' => $row->actor_id,
                        'event_type' => $row->event_type,
                        'subject_type' => $row->subject_type,
                        'subject_id' => $row->subject_id,
                        'metadata' => $row->metadata,
                        'ip_address' => $row->ip_address,
                        'occurred_at' => $row->occurred_at,
                    ];
                    $eventHash = $canonicalizer->hash($event);

                    DB::table('audit_events')->where('id', $row->id)->update([
                        'chain_sequence' => $sequence,
                        'previous_hash' => $previousHash,
                        'event_hash' => $eventHash,
                        'hash_version' => AuditEventCanonicalizer::VERSION,
                    ]);

                    $previousHash = $eventHash;
                }
            });

            Schema::table('audit_events', function (Blueprint $table): void {
                $table->unsignedBigInteger('chain_sequence')->nullable(false)->change();
                $table->string('previous_hash', 64)->nullable(false)->change();
                $table->string('event_hash', 64)->nullable(false)->change();
                $table->unsignedSmallInteger('hash_version')->nullable(false)->change();
                $table->unique('chain_sequence');
            });

            DB::table('audit_chain_heads')->where('id', 1)->update([
                'chain_sequence' => $sequence,
                'head_hash' => $previousHash,
                'updated_at' => now(),
            ]);
        } finally {
            $this->restoreAppendOnlyTrigger();
        }
    }

    private function suspendAppendOnlyTrigger(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_update_delete ON audit_events');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_truncate ON audit_events');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_delete');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_delete');
        } elseif ($driver === 'sqlsrv') {
            DB::unprepared('DISABLE TRIGGER audit_events_reject_update_delete ON audit_events');
        } else {
            throw new \RuntimeException('Unsupported database driver for audit-chain backfill: '.$driver);
        }
    }

    private function restoreAppendOnlyTrigger(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_reject_update_delete
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION prevent_audit_event_mutation();
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_reject_truncate
                BEFORE TRUNCATE ON audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION prevent_audit_event_mutation();
            SQL);
        } elseif ($driver === 'sqlite') {
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
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_reject_update
                BEFORE UPDATE ON audit_events
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'audit_events is append-only; UPDATE is prohibited'
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_reject_delete
                BEFORE DELETE ON audit_events
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'audit_events is append-only; DELETE is prohibited'
            SQL);
        } elseif ($driver === 'sqlsrv') {
            DB::unprepared('ENABLE TRIGGER audit_events_reject_update_delete ON audit_events');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_heads');
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropUnique(['chain_sequence']);
            $table->dropColumn(['chain_sequence', 'previous_hash', 'event_hash', 'hash_version']);
        });
    }
};
