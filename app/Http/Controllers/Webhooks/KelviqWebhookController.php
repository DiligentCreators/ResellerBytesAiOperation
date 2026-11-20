<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBillingWebhookReconcile;
use App\Services\Tenancy\TenantBillingWebhookGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class KelviqWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $rawBody = $request->getContent();

        // Kelviq "Verify endpoint" probe is an unsigned empty POST; signature
        // failure is expected and tells Kelviq the route accepts POST.
        if ($rawBody === '') {
            return response('missing signature', 400);
        }

        try {
            app(TenantBillingWebhookGateway::class)->ingestConfigured('kelviq', $rawBody, $request->headers->all());
        } catch (Throwable $exception) {
            Log::warning('Kelviq webhook rejected.', [
                'reason_code' => $exception->getMessage(),
                'exception_class' => $exception::class,
            ]);

            $message = $exception->getMessage();
            $status = str_contains($message, 'signature') || str_contains($message, 'timestamp')
                ? 400
                : 422;

            return response('rejected', $status);
        }

        ProcessBillingWebhookReconcile::dispatch();

        return response('ok', 200);
    }
}
