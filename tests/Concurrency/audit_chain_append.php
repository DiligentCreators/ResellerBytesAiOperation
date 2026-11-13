<?php

declare(strict_types=1);

use App\Services\Audit\AuditEventWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql') {
    throw new RuntimeException('audit_chain_concurrency_requires_postgresql');
}

$mode = $argv[1] ?? 'parent';

if ($mode === '--worker') {
    $workerId = $argv[2] ?? '';
    $barrierDirectory = $argv[3] ?? '';

    if ($workerId === '' || $barrierDirectory === '') {
        throw new RuntimeException('audit_chain_worker_arguments_invalid');
    }

    file_put_contents($barrierDirectory.'/ready-'.$workerId, 'ready', LOCK_EX);
    $deadline = microtime(true) + 30;

    while (! is_file($barrierDirectory.'/go')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('audit_chain_worker_barrier_timeout');
        }

        usleep(10_000);
    }

    try {
        $event = app(AuditEventWriter::class)->append([
            'actor_type' => 'system_operator',
            'actor_id' => 'audit-chain-concurrency-test',
            'event_type' => 'audit_chain.concurrent_append',
            'subject_type' => 'concurrency_test',
            'subject_id' => $workerId,
            'metadata' => ['worker_id' => $workerId],
            'occurred_at' => now(),
        ]);

        $result = [
            'status' => 'appended',
            'sequence' => $event->chain_sequence,
            'event_hash' => $event->event_hash,
        ];
    } catch (Throwable $exception) {
        $result = [
            'status' => 'error',
            'error' => $exception::class.': '.$exception->getMessage(),
        ];
    }

    file_put_contents(
        $barrierDirectory.'/result-'.$workerId.'.json',
        json_encode($result, JSON_THROW_ON_ERROR),
        LOCK_EX,
    );

    exit(($result['status'] === 'error') ? 1 : 0);
}

$workerCount = 8;
$barrierDirectory = sys_get_temp_dir().'/audit-chain-concurrency-'.Str::uuid();

if (! mkdir($barrierDirectory, 0700, true) && ! is_dir($barrierDirectory)) {
    throw new RuntimeException('audit_chain_test_temp_directory_failed');
}

$processes = [];

try {
    for ($workerId = 1; $workerId <= $workerCount; $workerId++) {
        $stdout = $barrierDirectory.'/stdout-'.$workerId;
        $stderr = $barrierDirectory.'/stderr-'.$workerId;
        $process = proc_open(
            [PHP_BINARY, __FILE__, '--worker', (string) $workerId, $barrierDirectory],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $stdout, 'w'],
                2 => ['file', $stderr, 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
            null,
            ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('audit_chain_worker_start_failed');
        }

        $processes[$workerId] = ['process' => $process, 'stderr' => $stderr];
    }

    $readyDeadline = microtime(true) + 30;
    do {
        $readyCount = 0;
        for ($workerId = 1; $workerId <= $workerCount; $workerId++) {
            if (is_file($barrierDirectory.'/ready-'.$workerId)) {
                $readyCount++;
            }
        }

        if ($readyCount === $workerCount) {
            break;
        }

        if (microtime(true) >= $readyDeadline) {
            throw new RuntimeException('audit_chain_workers_not_ready');
        }

        usleep(10_000);
    } while (true);

    file_put_contents($barrierDirectory.'/go', 'start', LOCK_EX);

    $results = [];
    foreach ($processes as $workerId => $processInfo) {
        $exitCode = proc_close($processInfo['process']);
        $processes[$workerId]['process'] = null;
        $resultPath = $barrierDirectory.'/result-'.$workerId.'.json';

        if (! is_file($resultPath)) {
            $stderr = is_file($processInfo['stderr']) ? file_get_contents($processInfo['stderr']) : '';
            throw new RuntimeException('audit_chain_worker_missing_result: '.$workerId.' '.$stderr);
        }

        $result = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
        if ($exitCode !== 0 || ($result['status'] ?? null) !== 'appended') {
            throw new RuntimeException('audit_chain_worker_failed: '.json_encode($result, JSON_THROW_ON_ERROR));
        }

        $results[] = $result;
    }

    $sequences = array_column($results, 'sequence');
    sort($sequences);
    if ($sequences !== range(1, $workerCount)) {
        throw new RuntimeException('audit_chain_sequences_not_contiguous: '.json_encode($sequences, JSON_THROW_ON_ERROR));
    }

    $head = DB::table('audit_chain_heads')->where('id', 1)->first();
    $lastEvent = DB::table('audit_events')->orderByDesc('chain_sequence')->first();

    if (DB::table('audit_events')->count() !== $workerCount
        || (int) $head->chain_sequence !== $workerCount
        || ! hash_equals((string) $lastEvent->event_hash, (string) $head->head_hash)) {
        throw new RuntimeException('audit_chain_final_head_mismatch');
    }

    if (Artisan::call('audit:verify-integrity') !== 0) {
        throw new RuntimeException('audit_chain_integrity_verifier_failed: '.Artisan::output());
    }

    fwrite(STDOUT, json_encode([
        'status' => 'passed',
        'database' => 'postgresql',
        'workers' => $workerCount,
        'contiguous_sequences' => $sequences,
        'final_head' => $head->head_hash,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
} finally {
    foreach ($processes as $processInfo) {
        if (is_resource($processInfo['process'])) {
            proc_terminate($processInfo['process']);
            proc_close($processInfo['process']);
        }
    }

    foreach (glob($barrierDirectory.'/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    rmdir($barrierDirectory);
}
