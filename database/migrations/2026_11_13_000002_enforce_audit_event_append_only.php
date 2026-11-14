<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_audit_event_mutation()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_events is append-only; UPDATE, DELETE and TRUNCATE are prohibited'
                        USING ERRCODE = '55000';
                END;
                $$;
            SQL);

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

            return;
        }

        if ($driver === 'sqlite') {
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

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
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

            return;
        }

        if ($driver === 'sqlsrv') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_reject_update_delete
                ON audit_events
                AFTER UPDATE, DELETE
                AS
                BEGIN
                    SET NOCOUNT ON;
                    IF EXISTS (SELECT 1 FROM inserted) OR EXISTS (SELECT 1 FROM deleted)
                    BEGIN
                        THROW 51000, 'audit_events is append-only; UPDATE and DELETE are prohibited', 1;
                    END
                END
            SQL);

            return;
        }

        throw new RuntimeException('Database driver does not support audit append-only triggers: '.$driver);
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_truncate ON audit_events');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_update_delete ON audit_events');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_audit_event_mutation()');

            return;
        }

        if (in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_delete');

            return;
        }

        if ($driver === 'sqlsrv') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_update_delete');

            return;
        }

        throw new RuntimeException('Database driver does not support audit append-only trigger removal: '.$driver);
    }
};
