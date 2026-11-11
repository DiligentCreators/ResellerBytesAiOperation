<?php

namespace App\Http\Middleware;

use App\Models\IntegrationRequestNonce;
use App\Models\WhmcsIntegration;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWhmcsIntegration
{
    private const MAX_BODY_BYTES = 262144;

    private const CLOCK_SKEW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query->count() > 0 || ! $request->isJson() || strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return response()->json(['message' => 'Invalid request.'], 400);
        }

        $integrationId = $request->header('X-RB-Integration');
        $timestamp = $request->header('X-RB-Timestamp');
        $nonce = $request->header('X-RB-Nonce');
        $signature = $request->header('X-RB-Signature');

        if (
            ! is_string($integrationId) || ! Str::isUuid($integrationId)
            || ! is_string($timestamp) || ! ctype_digit($timestamp)
            || ! is_string($nonce) || ! preg_match('/\A[A-Za-z0-9_-]{32,128}\z/', $nonce)
            || ! is_string($signature) || ! preg_match('/\A[a-f0-9]{64}\z/', $signature)
        ) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $requestTime = (int) $timestamp;

        if (abs(now()->timestamp - $requestTime) > self::CLOCK_SKEW_SECONDS) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $integration = WhmcsIntegration::query()
            ->whereKey($integrationId)
            ->where('status', 'active')
            ->first();

        if (! $integration) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
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
            return response()->json(['message' => 'Unauthenticated.'], 401);
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

            return response()->json(['message' => 'Request already used.'], 409);
        }

        $request->attributes->set('whmcs_integration', $integration);

        return $next($request);
    }
}
