# PostgreSQL role separation

Status: deployment procedure documented; production roles are not provisioned by this repository
Scope: Laravel Forge PostgreSQL database for ResellerBytes AI Operations

## Required identities

Use separate credentials and sessions for:

- `rb_migrator`: dedicated deployment-only login that owns the application schema and migration-created objects. It is used only for reviewed `php artisan migrate --force` operations. Do not put this password in the long-running FPM or queue-worker environment.
- `rb_runtime`: application login used by PHP-FPM and queue workers. It has no schema/database CREATE privilege and no access to the Laravel `migrations` table. It cannot alter or delete audit events.
- `rb_readonly`: reporting login with SELECT-only access to approved application tables. It has no write, sequence mutation, schema, role-management, or migration privileges; exclude sensitive tables/views unless explicitly approved for reporting.
- PostgreSQL cluster administrator: break-glass DBA identity used only for cluster administration and recovery. Never configure it in Laravel.

The migration login and cluster administrator must not be the same routine credential. Store both credentials in the approved secret manager with separate access policies.

## Provisioning template

Run as the PostgreSQL cluster administrator after substituting the database name and generating unique strong passwords in the secret manager. Do not commit real credentials.

```sql
-- Run while connected to the target database as the cluster administrator.
CREATE ROLE rb_migrator LOGIN PASSWORD '<MIGRATOR_SECRET>';
CREATE ROLE rb_runtime LOGIN PASSWORD '<RUNTIME_SECRET>';
CREATE ROLE rb_readonly LOGIN PASSWORD '<READONLY_SECRET>';

GRANT CONNECT ON DATABASE resellerbytes_ai_operation TO rb_migrator, rb_runtime, rb_readonly;

-- The migration identity owns application objects and is the only application
-- identity allowed to create/alter schema objects.
GRANT USAGE, CREATE ON SCHEMA public TO rb_migrator;
ALTER SCHEMA public OWNER TO rb_migrator;

-- Runtime is deliberately not a schema owner and cannot create objects.
GRANT USAGE ON SCHEMA public TO rb_runtime, rb_readonly;
REVOKE CREATE ON SCHEMA public FROM rb_runtime, rb_readonly;

-- Existing application tables. The migration ledger is excluded below.
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO rb_runtime;
DO $$
BEGIN
    IF to_regclass('public.migrations') IS NOT NULL THEN
        REVOKE ALL PRIVILEGES ON TABLE public.migrations FROM rb_runtime;
    END IF;
END;
$$;

-- Audit events are append-only for the runtime identity.
GRANT SELECT, INSERT ON TABLE audit_events TO rb_runtime;
REVOKE UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER ON TABLE audit_events FROM rb_runtime;

-- The chain head must be advanced by AuditEventWriter in the same transaction
-- as an append. Runtime therefore needs SELECT and UPDATE, but not INSERT/DELETE.
GRANT SELECT, UPDATE ON TABLE audit_chain_heads TO rb_runtime;
REVOKE INSERT, DELETE, TRUNCATE, REFERENCES, TRIGGER ON TABLE audit_chain_heads FROM rb_runtime;

-- Runtime can consume generated IDs, but cannot reset sequences with setval().
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO rb_runtime;

-- Reporting receives read-only access; do not grant sequence USAGE/UPDATE.
GRANT SELECT ON ALL TABLES IN SCHEMA public TO rb_readonly;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER ON ALL TABLES IN SCHEMA public FROM rb_readonly;
DO $
BEGIN
    IF to_regclass('public.migrations') IS NOT NULL THEN
        REVOKE ALL PRIVILEGES ON TABLE public.migrations FROM rb_readonly;
    END IF;
END;
$;

-- Ensure objects created by future migrations inherit runtime DML access.
-- The explicit audit_events and migrations revocations above must be reapplied
-- after every migration run (see deployment verification below).
ALTER DEFAULT PRIVILEGES FOR ROLE rb_migrator IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO rb_runtime;
ALTER DEFAULT PRIVILEGES FOR ROLE rb_migrator IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO rb_runtime;
ALTER DEFAULT PRIVILEGES FOR ROLE rb_migrator IN SCHEMA public
    GRANT SELECT ON TABLES TO rb_readonly;
```

The example assumes the Laravel migration session connects as `rb_migrator`, so newly created objects are owned by that identity. Confirm this with `SELECT current_user`, `\dt+`, and `\dp` before running migrations. If the deployment process uses a different role, adjust the `ALTER DEFAULT PRIVILEGES FOR ROLE` owner accordingly.

## Required post-migration privilege reconciliation

Run after every migration deployment, as the cluster administrator, before restarting application workers:

```sql
REVOKE ALL PRIVILEGES ON TABLE migrations FROM rb_runtime, rb_readonly;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO rb_readonly;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER ON ALL TABLES IN SCHEMA public FROM rb_readonly;
GRANT SELECT, INSERT ON TABLE audit_events TO rb_runtime;
REVOKE UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER ON TABLE audit_events FROM rb_runtime;
GRANT SELECT, UPDATE ON TABLE audit_chain_heads TO rb_runtime;
REVOKE INSERT, DELETE, TRUNCATE, REFERENCES, TRIGGER ON TABLE audit_chain_heads FROM rb_runtime;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO rb_runtime;
```

Do not grant the runtime login membership in `rb_migrator`, ownership of application tables, `CREATEDB`, `CREATEROLE`, or superuser privileges. Do not run migrations from PHP-FPM, queue workers, the scheduler, or the web dashboard.

## Verification queries

Run as the DBA and retain the sanitized output in the deployment evidence:

```sql
SELECT rolname, rolsuper, rolcreatedb, rolcreaterole, rolcanlogin
FROM pg_roles
WHERE rolname IN ('rb_migrator', 'rb_runtime', 'rb_readonly');

SELECT has_schema_privilege('rb_runtime', 'public', 'CREATE') AS runtime_can_create_schema;
SELECT has_schema_privilege('rb_readonly', 'public', 'CREATE') AS readonly_can_create_schema;
SELECT has_table_privilege('rb_readonly', 'audit_events', 'SELECT') AS readonly_can_read_audit;
SELECT has_table_privilege('rb_readonly', 'audit_events', 'INSERT') AS readonly_can_insert_audit;
SELECT has_table_privilege('rb_readonly', 'audit_events', 'UPDATE') AS readonly_can_update_audit;
SELECT has_table_privilege('rb_runtime', 'migrations', 'SELECT') AS runtime_can_read_migrations;
SELECT has_table_privilege('rb_runtime', 'audit_events', 'INSERT') AS runtime_can_insert_audit;
SELECT has_table_privilege('rb_runtime', 'audit_events', 'UPDATE') AS runtime_can_update_audit;
SELECT has_table_privilege('rb_runtime', 'audit_events', 'DELETE') AS runtime_can_delete_audit;
SELECT has_table_privilege('rb_runtime', 'audit_chain_heads', 'UPDATE') AS runtime_can_advance_chain_head;
```

Expected values: all three application logins are non-superusers; read-only can SELECT but cannot INSERT/UPDATE; only the migrator can create schema objects; runtime cannot read the migration ledger; runtime can insert but cannot update/delete audit events; runtime can update the chain head because the append transaction requires it.

Also connect using the runtime credential and attempt UPDATE, DELETE, and TRUNCATE against a disposable audit row in a non-production test database. Each must fail. Confirm normal audit append, chain verification, diagnostic ingestion, AI settlement, and nonce cleanup still work under the runtime login.

## Automated runtime-role regression test

The PostgreSQL CI job provisions a disposable `rb_runtime` login after migrations, grants the documented application DML baseline, then applies the migration-ledger and audit-table restrictions. It runs `tests/Postgres/RuntimeRolePrivilegesTest.php` using that login rather than the PostgreSQL administrator.

The regression test proves that the runtime identity can append a hash-chained audit event and verify the chain, while migration-ledger reads, schema creation, audit UPDATE/DELETE/TRUNCATE, and WHMCS integration hard DELETE are rejected. The test also confirms that the integration row remains present after the rejected delete. Its password is generated ephemerally inside the isolated CI job and is not a repository or production secret.

The production reporting login must additionally be tested with SELECT success and INSERT/UPDATE/DELETE/TRUNCATE/DDL denial against a disposable reporting schema before use. Reporting grants should be narrowed to an explicit table/view allowlist if any application table contains credentials, tokens, or customer-sensitive fields.

This automated check is a least-privilege regression guard, not a substitute for production role provisioning, a full application smoke test under the production runtime role, or an independent security review.

## Security boundary and limitations

- PostgreSQL table grants and triggers constrain the runtime login; a cluster superuser or object owner can still alter privileges, disable triggers, or rewrite data. Independent external audit anchoring remains required.
- The runtime role can update `audit_chain_heads`; this is required by the current writer design. The hash-chain verifier detects inconsistent state, while an external signed anchor is required to detect a privileged rewrite of the entire local chain.
- Do not apply these statements blindly to production. Rehearse on a production-like restored backup, validate every application code path under the runtime role, and retain a verified rollback credential before changing Forge environment variables.
- Production provisioning and verification remain operator/deployment tasks. No production database credentials or access have been used by this work.
