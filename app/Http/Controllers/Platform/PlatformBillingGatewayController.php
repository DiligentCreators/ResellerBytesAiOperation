<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBillingWebhookReconcile;
use App\Models\TenantBillingWebhookEvent;
use App\Services\Billing\BillingSettings;
use App\Services\CentralStaffAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PlatformBillingGatewayController extends Controller
{
    public function show(Request $request, BillingSettings $settings): Response
    {
        $this->authorizeBilling($request);

        $lastWebhook = TenantBillingWebhookEvent::query()
            ->where('provider', 'kelviq')
            ->latest('id')
            ->first();

        $failedCount = TenantBillingWebhookEvent::query()
            ->where('provider', 'kelviq')
            ->where('status', 'dead_lettered')
            ->count();

        $recent = TenantBillingWebhookEvent::query()
            ->where('provider', 'kelviq')
            ->latest('id')
            ->limit(25)
            ->get(['id', 'event_type', 'status', 'received_at', 'tenant_id', 'attempts', 'last_error_code']);

        $canManageSettings = app(CentralStaffAuthorization::class)
            ->can($request->user(), 'billing.settings.manage');

        return Inertia::render('platform/billing/gateway', [
            'gateway' => [
                'driver' => $settings->gateway(),
                'kelviq_mode' => $settings->kelviqMode(),
                'api_key_configured' => $settings->kelviqApiKey() !== '',
                'webhook_secret_configured' => $settings->kelviqWebhookSecret() !== '',
                'webhook_url' => url('/webhooks/kelviq'),
                'last_webhook_received_at' => $lastWebhook?->received_at?->toIso8601String(),
                'failed_webhook_count' => $failedCount,
            ],
            'canManageSettings' => $canManageSettings,
            'recentWebhooks' => $recent,
        ]);
    }

    public function replay(Request $request, TenantBillingWebhookEvent $webhook): RedirectResponse
    {
        $this->authorizeBilling($request);
        abort_unless($webhook->provider === 'kelviq', 404);

        DB::transaction(function () use ($webhook): void {
            $event = TenantBillingWebhookEvent::query()->whereKey($webhook->id)->lockForUpdate()->firstOrFail();
            if (! in_array($event->status, ['dead_lettered', 'processed', 'pending'], true)) {
                return;
            }

            $event->forceFill([
                'status' => 'pending',
                'available_at' => now('UTC'),
                'locked_at' => null,
                'dead_lettered_at' => null,
                'last_error_code' => null,
                'manual_replay_count' => $event->manual_replay_count + 1,
            ])->save();
        }, 3);

        ProcessBillingWebhookReconcile::dispatch();

        return back()->with('status', 'Webhook queued for replay.');
    }

    private function authorizeBilling(Request $request): void
    {
        abort_unless(app(CentralStaffAuthorization::class)->can($request->user(), 'billing'), 403);
    }
}
