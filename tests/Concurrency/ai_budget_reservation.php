<?php

declare(strict_types=1);

use App\Models\AiBudgetPeriod;
use App\Models\AiUsageAttempt;
use App\Models\DiagnosticRun;
use App\Models\WhmcsIntegration;
use App\Services\Diagnostics\AiBudgetService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql') {
    throw new RuntimeException('ai_budget_concurrency_requires_postgresql');
}

function runConcurrencyScenario(
    string $scenario,
    int $dailyLimit,
    int $monthlyLimit,
    int $workerCount,
    int $expectedReservation,
    WhmcsIntegration $integration,
): array {
    config()->set('ai_budget.daily_limit_micro_usd', $dailyLimit);
    config()->set('ai_budget.monthly_limit_micro_usd', $monthlyLimit);

    $expectedSuccesses = 3;
    $expectedReservedTotal = $expectedReservation * $expectedSuccesses;
    $runs = [];

    for ($index = 0; $index < $workerCount; $index++) {
        $runs[] = DiagnosticRun::query()->create([
            'integration_id' => $integration->id,
            'snapshot_uuid' => (string) Str::uuid(),
            'schema_version' => '1.0',
            'addon_version' => '1.0.0',
            'status' => 'analyzing',
            'analysis_started_at' => now(),
            'analysis_attempts' => 1,
            'collected_at' => now(),
            'received_at' => now(),
            'payload' => ['system' => ['whmcs_version' => '8.13.7']],
            'payload_sha256' => hash('sha256', $scenario.'-'.$index),
        ]);
    }

    $barrierDirectory = sys_get_temp_dir().'/ai-budget-concurrency-'.Str::uuid();
    if (! mkdir($barrierDirectory, 0700, true) && ! is_dir($barrierDirectory)) {
        throw new RuntimeException('concurrency_test_temp_directory_failed');
    }

    $processes = [];

    try {
        foreach ($runs as $index => $run) {
            $stdout = $barrierDirectory.'/stdout-'.$index;
            $stderr = $barrierDirectory.'/stderr-'.$index;
            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $stdout, 'w'],
                2 => ['file', $stderr, 'w'],
            ];

            $process = proc_open(
                [
                    PHP_BINARY,
                    __FILE__,
                    '--worker',
                    $run->id,
                    $barrierDirectory,
                    (string) $dailyLimit,
                    (string) $monthlyLimit,
                ],
                $descriptors,
                $pipes,
                dirname(__DIR__, 2),
                null,
                ['bypass_shell' => true],
            );

            if (! is_resource($process)) {
                throw new RuntimeException('concurrency_test_worker_start_failed');
            }

            $processes[$run->id] = [
                'process' => $process,
                'stderr' => $stderr,
            ];
        }

        $readyDeadline = microtime(true) + 30;
        do {
            $readyCount = 0;
            foreach ($runs as $run) {
                if (is_file($barrierDirectory.'/ready-'.$run->id)) {
                    $readyCount++;
                }
            }

            if ($readyCount === $workerCount) {
                break;
            }

            if (microtime(true) >= $readyDeadline) {
                throw new RuntimeException('concurrency_test_workers_not_ready');
            }

            usleep(10_000);
        } while (true);

        file_put_contents($barrierDirectory.'/go', 'start', LOCK_EX);

        $results = [];
        foreach ($processes as $runId => $processInfo) {
            $exitCode = proc_close($processInfo['process']);
            $processes[$runId]['process'] = null;
            $resultPath = $barrierDirectory.'/result-'.$runId.'.json';

            if (! is_file($resultPath)) {
                $stderr = is_file($processInfo['stderr']) ? file_get_contents($processInfo['stderr']) : '';
                throw new RuntimeException('concurrency_test_worker_missing_result: '.$runId.' '.$stderr);
            }

            $result = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
            if ($exitCode !== 0 || ($result['status'] ?? null) === 'error') {
                throw new RuntimeException('concurrency_test_worker_failed: '.json_encode($result, JSON_THROW_ON_ERROR));
            }

            $results[] = $result;
        }

        $reservedResults = array_filter($results, fn (array $result): bool => $result['status'] === 'reserved');
        $exhaustedResults = array_filter($results, fn (array $result): bool => $result['status'] === 'budget_exhausted');

        if (count($reservedResults) !== $expectedSuccesses || count($exhaustedResults) !== ($workerCount - $expectedSuccesses)) {
            throw new RuntimeException('concurrency_test_unexpected_worker_outcomes: '.json_encode([
                'scenario' => $scenario,
                'reserved' => count($reservedResults),
                'budget_exhausted' => count($exhaustedResults),
            ], JSON_THROW_ON_ERROR));
        }

        $attemptCount = AiUsageAttempt::query()->count();
        $attemptReservedTotal = (int) AiUsageAttempt::query()->sum('reserved_micro_usd');
        $periods = AiBudgetPeriod::query()->get()->keyBy('period_type');

        if ($attemptCount !== $expectedSuccesses || $attemptReservedTotal !== $expectedReservedTotal) {
            throw new RuntimeException('concurrency_test_attempt_ledger_mismatch: '.$scenario);
        }

        foreach (['daily' => $dailyLimit, 'monthly' => $monthlyLimit] as $periodType => $limit) {
            $period = $periods->get($periodType);
            if ($period === null
                || (int) $period->limit_micro_usd !== $limit
                || (int) $period->reserved_micro_usd !== $expectedReservedTotal) {
                throw new RuntimeException('concurrency_test_period_reservation_mismatch: '.$scenario.'/'.$periodType);
            }

            if ((int) $period->committed_micro_usd !== 0) {
                throw new RuntimeException('concurrency_test_unexpected_committed_spend: '.$scenario.'/'.$periodType);
            }
        }

        if ($periods->count() !== 2) {
            throw new RuntimeException('concurrency_test_unexpected_period_count: '.$scenario);
        }

        return [
            'scenario' => $scenario,
            'workers' => $workerCount,
            'reservations_accepted' => count($reservedResults),
            'reservations_rejected_at_budget_limit' => count($exhaustedResults),
            'reservation_each_micro_usd' => $expectedReservation,
            'limiting_budget_micro_usd' => $expectedReservedTotal + 1_000,
            'reserved_total_micro_usd' => $expectedReservedTotal,
            'attempt_rows' => $attemptCount,
            'daily_and_monthly_ledger_match' => true,
        ];
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
}

$mode = $argv[1] ?? 'parent';

if ($mode === '--worker') {
    $runId = $argv[2] ?? '';
    $barrierDirectory = $argv[3] ?? '';
    $dailyLimit = filter_var($argv[4] ?? null, FILTER_VALIDATE_INT);
    $monthlyLimit = filter_var($argv[5] ?? null, FILTER_VALIDATE_INT);

    if ($runId === '' || $barrierDirectory === '' || $dailyLimit === false || $monthlyLimit === false) {
        throw new RuntimeException('worker_arguments_invalid');
    }

    config()->set('ai_budget.daily_limit_micro_usd', $dailyLimit);
    config()->set('ai_budget.monthly_limit_micro_usd', $monthlyLimit);

    $run = DiagnosticRun::query()->findOrFail($runId);
    file_put_contents($barrierDirectory.'/ready-'.$runId, 'ready', LOCK_EX);

    $deadline = microtime(true) + 30;
    while (! is_file($barrierDirectory.'/go')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('worker_barrier_timeout');
        }

        usleep(10_000);
    }

    try {
        $attempt = app(AiBudgetService::class)->reserve($run, 'gpt-5.6-sol', 'concurrency-test.v1');
        $result = [
            'status' => 'reserved',
            'attempt_id' => $attempt->id,
        ];
    } catch (RuntimeException $exception) {
        $result = $exception->getMessage() === 'budget_exhausted'
            ? ['status' => 'budget_exhausted']
            : ['status' => 'error', 'error' => $exception->getMessage()];
    } catch (Throwable $exception) {
        $result = [
            'status' => 'error',
            'error' => $exception::class.': '.$exception->getMessage(),
        ];
    }

    file_put_contents(
        $barrierDirectory.'/result-'.$runId.'.json',
        json_encode($result, JSON_THROW_ON_ERROR),
        LOCK_EX,
    );

    exit(($result['status'] === 'error') ? 1 : 0);
}

$workerCount = 8;
$expectedReservation = intdiv((65_536 * 40) + (3_000 * 200) + 9, 10);
$limitingBudget = ($expectedReservation * 3) + 1_000;

if ((int) config('ai_budget.max_input_bytes') !== 65_536
    || (int) config('ai_budget.max_output_tokens') !== 3_000) {
    throw new RuntimeException('concurrency_test_reservation_configuration_invalid');
}

$integration = WhmcsIntegration::query()->create([
    'name' => 'PostgreSQL budget concurrency '.Str::uuid(),
    'status' => 'active',
    'secret' => bin2hex(random_bytes(32)),
    'secret_version' => 1,
]);

$scenarios = [
    runConcurrencyScenario('daily-budget-contention', $limitingBudget, 50_000_000, $workerCount, $expectedReservation, $integration),
];

DB::table('ai_usage_attempts')->delete();
DB::table('ai_budget_periods')->delete();

$scenarios[] = runConcurrencyScenario('monthly-budget-contention', 50_000_000, $limitingBudget, $workerCount, $expectedReservation, $integration);

fwrite(STDOUT, json_encode([
    'status' => 'passed',
    'database' => 'postgresql',
    'scenarios' => $scenarios,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
