<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'sqlsrv') {
    fwrite(STDERR, "This integration test requires the SQL Server driver.\n");
    exit(2);
}

DB::statement("IF DB_ID(N'testing') IS NULL CREATE DATABASE [testing]");
DB::purge('sqlsrv');
config(['database.connections.sqlsrv.database' => 'testing']);
DB::reconnect('sqlsrv');

Schema::dropIfExists('whmcs_integrations');
Schema::create('whmcs_integrations', function (Blueprint $table): void {
    $table->bigIncrements('id');
});

$migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_14_000001_enforce_whmcs_integration_retention.php';
$migration->up();

$firstId = DB::table('whmcs_integrations')->insertGetId([]);
$secondId = DB::table('whmcs_integrations')->insertGetId([]);
$rejected = false;

try {
    DB::table('whmcs_integrations')->whereIn('id', [$firstId, $secondId])->delete();
} catch (QueryException $exception) {
    $rejected = str_contains($exception->getMessage(), 'whmcs_integrations are retained for audit history');
}

$preserved = DB::table('whmcs_integrations')->whereIn('id', [$firstId, $secondId])->count() === 2;
$migration->down();
Schema::dropIfExists('whmcs_integrations');

if (! $rejected || ! $preserved) {
    fwrite(STDERR, "SQL Server retention trigger failed to reject a multi-row DELETE and preserve every row.\n");
    exit(1);
}

echo "SQL Server WHMCS integration retention trigger test passed.\n";
