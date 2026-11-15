<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql') {
    throw new RuntimeException('readonly_role_privilege_test_requires_postgresql');
}

function expectReadOnlyDenied(string $label, Closure $operation): void
{
    try {
        $operation();
    } catch (QueryException) {
        fwrite(STDOUT, $label.': denied as expected'.PHP_EOL);

        return;
    }

    throw new RuntimeException('readonly_role_privilege_was_not_denied: '.$label);
}

$count = DB::table('readonly_role_probe')->count();
if (! is_int($count) && ! ctype_digit((string) $count)) {
    throw new RuntimeException('readonly_role_could_not_select_approved_table');
}

expectReadOnlyDenied('unapproved audit SELECT', fn () => DB::table('audit_events')->count());

expectReadOnlyDenied('audit INSERT', fn () => DB::table('audit_events')->insert([
    'event_type' => 'readonly.role.must_not_insert',
    'occurred_at' => now(),
]));

expectReadOnlyDenied('audit UPDATE', fn () => DB::table('audit_events')->update([
    'event_type' => 'readonly.role.must_not_update',
]));

expectReadOnlyDenied('audit DELETE', fn () => DB::table('audit_events')->delete());

expectReadOnlyDenied('audit TRUNCATE', fn () => DB::statement('TRUNCATE TABLE audit_events'));

expectReadOnlyDenied('migration ledger SELECT', fn () => DB::table('migrations')->count());

expectReadOnlyDenied('schema CREATE', fn () => DB::statement('CREATE TABLE readonly_role_must_not_create (id integer)'));

fwrite(STDOUT, json_encode([
    'status' => 'passed',
    'database' => 'postgresql',
    'read_only_role' => DB::selectOne('SELECT current_user AS role')->role,
    'explicitly_granted_reporting_view_select' => 'allowed',
    'unapproved_audit_select' => 'denied',
    'audit_insert_update_delete_truncate' => 'denied',
    'migration_ledger_read' => 'denied',
    'schema_create' => 'denied',
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
