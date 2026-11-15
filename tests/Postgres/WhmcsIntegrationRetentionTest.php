<?php

use App\Models\WhmcsIntegration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('rejects query-builder deletion of a revoked integration on PostgreSQL', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $integration = WhmcsIntegration::create([
        'name' => 'PostgreSQL retained integration',
        'status' => 'revoked',
        'secret' => str_repeat('f', 64),
        'secret_version' => 4,
    ]);

    DB::statement('SAVEPOINT whmcs_integration_delete_attempt');
    $exception = null;

    try {
        DB::table('whmcs_integrations')->where('id', $integration->id)->delete();
    } catch (QueryException $queryException) {
        $exception = $queryException;
    }

    DB::statement('ROLLBACK TO SAVEPOINT whmcs_integration_delete_attempt');

    expect($exception)->toBeInstanceOf(QueryException::class)
        ->and($exception->getMessage())->toContain('whmcs_integrations are retained for audit history')
        ->and(DB::table('whmcs_integrations')->where('id', $integration->id)->exists())->toBeTrue();
});
