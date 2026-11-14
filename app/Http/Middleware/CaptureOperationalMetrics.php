<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class CaptureOperationalMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->record(500, (microtime(true) - $startedAt) * 1000);

            throw $exception;
        }

        $this->record($response->getStatusCode(), (microtime(true) - $startedAt) * 1000);

        return $response;
    }

    private function record(int $statusCode, float $durationMs): void
    {
        try {
            DB::table('operations_metric_samples')->insert([
                'status_code' => $statusCode,
                'duration_ms' => max(0, $durationMs),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // Telemetry must never turn an otherwise successful API request into a failure.
            Log::warning('Operational API metric sample could not be persisted.', [
                'exception_class' => $exception::class,
            ]);
        }
    }
}
