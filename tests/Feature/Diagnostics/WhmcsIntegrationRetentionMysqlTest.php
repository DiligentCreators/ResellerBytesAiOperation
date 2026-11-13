<?php

use App\Models\WhmcsIntegration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('rejects query-builder deletion of a revoked integration on MySQL', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This integration test requires MySQL or MariaDB.');
    }

    $integration = WhmcsIntegration::create([
        'name' => 'MySQL retained integration',
        'status' => 'revoked',
        'secret' => str_repeat('f', 64),
        'secret_version' => 4,
    ]);

    expect(fn () => DB::table('whmcs_integrations')->where('id', $integration->id)->delete())
        ->toThrow(QueryException::class, 'whmcs_integrations are retained for audit history');

    expect(DB::table('whmcs_integrations')->where('id', $integration->id)->exists())->toBeTrue();
});
