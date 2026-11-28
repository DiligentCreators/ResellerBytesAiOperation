<?php

namespace App\Http\Middleware;

use App\Models\IntegrationRequestNonce;
use App\Models\Tenant;
use App\Models\WhmcsIntegration;
use App\Services\Tenancy\TenantDatabaseContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuthenticateWhmcsIntegration
{
    private const MAX_BODY_BYTES = 262144;

    private const CLOCK_SKEW_SECONDS = 300;

    private const LOG_LIMIT_PER_MINUTE = 30;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query->count() > 0) {
            return $this->reject($request, 'query_string_present', 400);
        }

        $content = $request->getContent();
        $isGet = $request->isMethod('GET');

        if ($isGet) {
            // Signed GET pulls may send an empty body or "{}".
            if ($content !== '' && (! $request->isJson() || strlen($content) > self::MAX_BODY_BYTES)) {
                return $this->reject($request, 'body_invalid', 400);
            }
        } elseif (! $request->isJson() || strlen($content) > self::MAX_BODY_BYTES) {
            return $this->reject($request, 'body_invalid', 400);
        }

        $integrationId = $request->header('X-RB-Integration');
        $timestamp = $request->header('X-RB-Timestamp');
        $nonce = $request->header('X-RB-Nonce');
        $signature = $request->header('X-RB-Signature');

        if (
            ! is_string($integrationId) || ! Str::isUuid($integrationId)
            || ! is_string($timestamp) || ! preg_match('/\A[0-9]{10}\z/', $timestamp)
            || ! is_string($nonce) || ! preg_match('/\A[A-Za-z0-9_-]{32,128}\z/', $nonce)
            || ! is_string($signature) || ! preg_match('/\A[a-f0-9]{64}\z/', $signature)
        ) {
            return $this->reject($request, 'headers_invalid', 401);
        }

        $requestTime = (int) $timestamp;
        $currentTimestamp = (int) now()->timestamp;
        $skewSeconds = abs($currentTimestamp - $requestTime);

        if ($skewSeconds > self::CLOCK_SKEW_SECONDS) {
            return $this->reject($request, 'clock_skew', 401, [
                'skew_seconds' => $skewSeconds,
            ]);
        }

        $integration = app(TenantDatabaseContext::class)->run(
            null,
            null,
            fn (): ?WhmcsIntegration => WhmcsIntegration::query()
                ->whereKey($integrationId)
                ->where('status', 'active')
                ->first(),
            $integrationId,
        );

        if (! $integration) {
            return $this->reject($request, 'integration_not_found_or_inactive', 401);
        }

        $bodyHash = hash('sha256', $request->getContent());
        $canonicalMessage = implode("\n", [
            strtoupper($request->method()),
            $request->getPathInfo(),
            $timestamp,
            $nonce,
            $bodyHash,
        ]);

        $expectedSignature = hash_hmac('sha256', $canonicalMessage, $integration->secret);

        if (! hash_equals($expectedSignature, $signature)) {
            return $this->reject($request, 'signature_mismatch', 401);
        }

        try {
            IntegrationRequestNonce::create([
                'integration_id' => $integration->id,
                'nonce' => $nonce,
                'expires_at' => now()->addMinutes(10),
                'created_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $sqlState = $exception->errorInfo[0] ?? null;
            $isUniqueViolation = $sqlState === '23505'
                || ($sqlState === '23000' && preg_match('/unique constraint|duplicate entry/i', $exception->getMessage()));

            if (! $isUniqueViolation) {
                throw $exception;
            }

            return $this->reject($request, 'nonce_replay', 409);
        }

        $request->attributes->set('whmcs_integration', $integration);

        return app(TenantDatabaseContext::class)->run(
            $integration->tenant_id,
            $integration->owner_user_id === null ? null : (int) $integration->owner_user_id,
            function () use ($integration, $next, $request): Response {
                if ($integration->tenant_id !== null
                    && ! Tenant::query()->whereKey($integration->tenant_id)->where('status', Tenant::STATUS_ACTIVE)->exists()) {
                    return $this->reject($request, 'tenant_inactive', 403);
                }

                try {
                    $response = $next($request);
                } catch (Throwable $exception) {
                    $status = $this->statusFromException($exception);
                    if ($status >= 400) {
                        $this->logRejection(
                            $request,
                            $this->reasonFromStatus($status),
                            $status,
                            $this->extraContextForStatus($status, null, $exception),
                        );
                    }

                    throw $exception;
                }

                if ($response->getStatusCode() >= 400) {
                    $status = $response->getStatusCode();
                    $this->logRejection(
                        $request,
                        $this->reasonFromStatus($status),
                        $status,
                        $this->extraContextForStatus($status, $response, null),
                    );
                }

                return $response;
            },
            $integration->id,
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function reject(Request $request, string $reason, int $status, array $extra = []): Response
    {
        $this->logRejection($request, $reason, $status, $extra);

        $message = match ($status) {
            400 => 'Invalid request.',
            403 => 'Integration unavailable.',
            409 => 'Request already used.',
            default => 'Unauthenticated.',
        };

        return response()->json(['message' => $message], $status);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function logRejection(Request $request, string $reason, int $status, array $extra = []): void
    {
        $context = array_merge([
            'reason' => $reason,
            'status' => $status,
            'integration_prefix' => $this->integrationPrefix($request->header('X-RB-Integration')),
            'route' => $request->route()?->getName() ?? $request->getPathInfo(),
            'method' => strtoupper($request->method()),
            'ip' => $request->ip(),
        ], $extra);

        $key = 'whmcs-auth-reject:'.$reason.':'.(string) $request->ip();

        try {
            $logged = RateLimiter::attempt(
                $key,
                self::LOG_LIMIT_PER_MINUTE,
                function () use ($context): void {
                    Log::warning('WHMCS integration request rejected.', $context);
                },
                60,
            );

            if ($logged === false) {
                return;
            }
        } catch (Throwable) {
            Log::warning('WHMCS integration request rejected.', $context);
        }
    }

    private function integrationPrefix(mixed $integrationId): ?string
    {
        if (! is_string($integrationId) || ! Str::isUuid($integrationId)) {
            return null;
        }

        return substr($integrationId, 0, 8);
    }

    private function reasonFromStatus(int $status): string
    {
        return match (true) {
            $status === 403 => 'subscription_or_quota_denied',
            $status === 404 => 'not_found',
            $status === 409 => 'snapshot_conflict',
            $status === 422 => 'validation_failed',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'server_error',
            default => 'rejected',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function extraContextForStatus(int $status, ?Response $response, ?Throwable $exception): array
    {
        if ($status !== 422) {
            return [];
        }

        $fields = [];

        if ($exception instanceof ValidationException) {
            $fields = array_keys($exception->errors());
        } elseif ($response !== null) {
            $payload = json_decode($response->getContent() ?: '', true);
            if (is_array($payload) && isset($payload['errors']) && is_array($payload['errors'])) {
                $fields = array_keys($payload['errors']);
            }
        }

        return [
            'validation_fields' => array_values(array_slice($fields, 0, 20)),
        ];
    }

    private function statusFromException(Throwable $exception): int
    {
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode();
        }

        if ($exception instanceof ValidationException) {
            return (int) $exception->status;
        }

        if ($exception instanceof AuthorizationException) {
            return 403;
        }

        if ($exception instanceof AuthenticationException) {
            return 401;
        }

        if ($exception instanceof ModelNotFoundException) {
            return 404;
        }

        return 500;
    }
}
