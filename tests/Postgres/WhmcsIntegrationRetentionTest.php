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

    expect(fn () => DB::table('whmcs_integrations')->where('id', $integration->id)->delete())
        ->toThrow(QueryException::class, 'whmcs_integrations are retained for audit history');

    expect(DB::table('whmcs_integrations')->where('id', $integration->id)->exists())->toBeTrue();
});
