<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_whmcs_integration_delete()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION 'whmcs_integrations are retained for audit history; revoke instead of deleting'
                        USING ERRCODE = '55000';
                END;
                $$;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER whmcs_integrations_reject_delete
                BEFORE DELETE ON whmcs_integrations
                FOR EACH ROW EXECUTE FUNCTION prevent_whmcs_integration_delete();
            SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER whmcs_integrations_reject_delete
                BEFORE DELETE ON whmcs_integrations
                BEGIN
                    SELECT RAISE(ABORT, 'whmcs_integrations are retained for audit history; revoke instead of deleting');
                END;
            SQL);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER whmcs_integrations_reject_delete
                BEFORE DELETE ON whmcs_integrations
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'whmcs_integrations are retained for audit history; revoke instead of deleting'
            SQL);

            return;
        }

        if ($driver === 'sqlsrv') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER whmcs_integrations_reject_delete
                ON whmcs_integrations
                AFTER DELETE
                AS
                BEGIN
                    SET NOCOUNT ON;
                    IF EXISTS (SELECT 1 FROM deleted)
                    BEGIN
                        THROW 51001, 'whmcs_integrations are retained for audit history; revoke instead of deleting', 1;
                    END
                END
            SQL);

            return;
        }

        throw new RuntimeException('Database driver does not support WHMCS integration retention triggers: '.$driver);
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS whmcs_integrations_reject_delete ON whmcs_integrations');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_whmcs_integration_delete()');

            return;
        }

        if (in_array($driver, ['sqlite', 'mysql', 'mariadb', 'sqlsrv'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS whmcs_integrations_reject_delete');

            return;
        }

        throw new RuntimeException('Database driver does not support WHMCS integration retention trigger removal: '.$driver);
    }
};
