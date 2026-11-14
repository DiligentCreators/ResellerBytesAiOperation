<?php

declare(strict_types=1);

use App\\Services\\Audit\\AuditEventWriter;
use Illuminate\\Contracts\\Console\\Kernel;
use Illuminate\\Database\\QueryException;
use Illuminate\\Support\\Facades\\Artisan;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql') {
    throw new RuntimeException('runtime_role_privilege_test_requires_postgresql');
}

function expectPrivilegeDenied(string $label, Closure $operation): void
{
    try {
        $operation();
    } catch (QueryException) {
        fwrite(STDOUT, $label.": denied as expected".PHP_EOL);

        return;
    }

    throw new RuntimeException('runtime_role_privilege_was_not_denied: '.$label);
}

$event = app(AuditEventWriter::class)->append([
    'actor_type' => 'system_operator',
    'actor_id' => 'runtime-role-ci-test',
    'event_type' => 'runtime_role.privilege_test',
    'subject_type' => 'security_test',
    'subject_id' => (string) Str::uuid(),
    'metadata' => ['environment' => 'isolated-ci'],
    'occurred_at' => now(),
]);

if (! $event->exists) {
    throw new RuntimeException('runtime_role_could_not_append_audit_event');
}

expectPrivilegeDenied('audit UPDATE', fn () => DB::table('audit_events')
    ->where('id', $event->id)
    ->update(['event_type' => 'tampered']));

expectPrivilegeDenied('audit DELETE', fn () => DB::table('audit_events')
    ->where('id', $event->id)
    ->delete());

expectPrivilegeDenied('audit TRUNCATE', fn () => DB::statement('TRUNCATE TABLE audit_events'));

expectPrivilegeDenied('migration ledger SELECT', fn () => DB::table('migrations')->count());

expectPrivilegeDenied('schema CREATE', fn () => DB::statement('CREATE TABLE runtime_role_must_not_create (id integer)'));

$integrationId = (string) Str::uuid();
DB::table('whmcs_integrations')->insert([
    'id' => $integrationId,
    'name' => 'Runtime role retention test',
    'status' => 'active',
    'secret' => bin2hex(random_bytes(32)),
    'secret_version' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);

expectPrivilegeDenied('integration hard DELETE', fn () => DB::table('whmcs_integrations')
    ->where('id', $integrationId)
    ->delete());

if (DB::table('whmcs_integrations')->where('id', $integrationId)->doesntExist()) {
    throw new RuntimeException('runtime_role_integration_retention_row_missing');
}

if (Artisan::call('audit:verify-integrity') !== 0) {
    throw new RuntimeException('runtime_role_audit_chain_verification_failed: '.Artisan::output());
}

fwrite(STDOUT, json_encode([
    'status' => 'passed',
    'database' => 'postgresql',
    'runtime_role' => DB::selectOne('SELECT current_user AS role')->role,
    'audit_append' => 'allowed',
    'audit_mutations' => 'denied',
    'migration_ledger_read' => 'denied',
    'schema_create' => 'denied',
    'integration_hard_delete' => 'denied',
    'audit_chain_integrity' => 'passed',
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
