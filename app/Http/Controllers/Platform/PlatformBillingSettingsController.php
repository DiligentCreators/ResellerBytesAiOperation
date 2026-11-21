<?php

namespace App\Http\Controllers\Platform;

use App\Billing\Kelviq\KelviqClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateBillingGatewaySettingsRequest;
use App\Models\BillingGatewaySetting;
use App\Services\Audit\AuditEventWriter;
use App\Services\Billing\BillingSettings;
use App\Services\CentralStaffAuthorization;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class PlatformBillingSettingsController extends Controller
{
    /**
     * @var list<string>
     */
    private const SECRET_FIELDS = [
        'kelviq_sandbox_api_key',
        'kelviq_live_api_key',
        'kelviq_sandbox_webhook_secret',
        'kelviq_live_webhook_secret',
    ];

    private function authorizeManage(Request $request): void
    {
        abort_unless(
            app(CentralStaffAuthorization::class)->can($request->user(), 'billing.settings.manage'),
            403,
        );
    }

    public function edit(Request $request, BillingSettings $settings): Response
    {
        $this->authorizeManage($request);

        return Inertia::render('platform/billing/settings', [
            'settings' => [
                'gateway' => $settings->gateway(),
                'gateway_source' => $settings->source('gateway'),
                'kelviq_mode' => $settings->kelviqMode(),
                'kelviq_mode_source' => $settings->source('kelviq_mode'),
                'kelviq_sandbox_api_key' => $settings->secretPresentation('kelviq_sandbox_api_key'),
                'kelviq_live_api_key' => $settings->secretPresentation('kelviq_live_api_key'),
                'kelviq_sandbox_webhook_secret' => $settings->secretPresentation('kelviq_sandbox_webhook_secret'),
                'kelviq_live_webhook_secret' => $settings->secretPresentation('kelviq_live_webhook_secret'),
                'trials_enabled' => $settings->trialsEnabled(),
                'trial_card_required' => $settings->trialCardRequired(),
                'trial_first_time_only' => $settings->trialFirstTimeOnly(),
                'webhook_url' => route('webhooks.kelviq', absolute: true),
            ],
            'status' => $request->session()->get('status'),
            'connection_test' => $request->session()->get('connection_test'),
        ]);
    }

    public function update(
        UpdateBillingGatewaySettingsRequest $request,
        AuditEventWriter $audit,
    ): RedirectResponse {
        $validated = $request->validated();
        $existing = BillingGatewaySetting::query()->find(1);

        $attributes = [
            'gateway' => $validated['gateway'],
            'kelviq_mode' => $validated['kelviq_mode'],
            'trials_enabled' => (bool) $validated['trials_enabled'],
            'trial_card_required' => (bool) $validated['trial_card_required'],
            'trial_first_time_only' => (bool) $validated['trial_first_time_only'],
            'updated_by_user_id' => $request->user()?->getKey(),
        ];

        $changes = [];

        foreach (['gateway', 'kelviq_mode', 'trials_enabled', 'trial_card_required', 'trial_first_time_only'] as $field) {
            $old = $existing?->{$field};
            $new = $attributes[$field];
            if ($existing === null || $old !== $new) {
                $changes[$field] = [
                    'old' => $existing === null ? null : $old,
                    'new' => $new,
                ];
            }
        }

        foreach (self::SECRET_FIELDS as $field) {
            $clearFlag = 'clear_'.$field;
            $clear = (bool) ($validated[$clearFlag] ?? false);
            $incoming = $request->input($field);

            if ($clear) {
                $hadValue = $existing !== null && filled($existing->{$field});
                $attributes[$field] = null;
                if ($hadValue) {
                    $changes[$field] = ['action' => 'cleared'];
                }

                continue;
            }

            if (is_string($incoming) && trim($incoming) !== '') {
                $attributes[$field] = trim($incoming);
                $changes[$field] = ['action' => 'replaced'];

                continue;
            }

            // Blank = keep current DB value (or leave null if no row yet).
            if ($existing !== null) {
                $attributes[$field] = $existing->{$field};
            }
        }

        if ($changes === []) {
            return redirect()
                ->route('platform.billing.settings.edit')
                ->with('status', 'No billing settings changes detected.');
        }

        DB::transaction(function () use ($attributes, $request, $audit, $changes, $existing): void {
            BillingGatewaySetting::query()->updateOrCreate(['id' => 1], $attributes);

            $audit->append([
                'actor_type' => 'user',
                'actor_id' => (string) $request->user()->id,
                'event_type' => 'billing.settings.updated',
                'subject_type' => BillingGatewaySetting::class,
                'subject_id' => '1',
                'metadata' => [
                    'changed_fields' => $changes,
                    'created' => $existing === null,
                ],
                'ip_address' => $request->ip(),
                'occurred_at' => now(),
            ]);
        });

        return redirect()
            ->route('platform.billing.settings.edit')
            ->with('status', 'Billing gateway settings saved.');
    }

    public function testConnection(
        Request $request,
        KelviqClient $client,
        BillingSettings $settings,
        AuditEventWriter $audit,
    ): RedirectResponse {
        $this->authorizeManage($request);

        $ok = false;
        $status = null;
        $message = 'Connection failed.';

        try {
            if ($settings->kelviqApiKey() === '') {
                $message = 'No API key is resolvable for the current Kelviq mode.';
            } else {
                $client->listWebhookLogs(pageSize: 1);
                $ok = true;
                $status = 200;
                $message = 'Kelviq connection OK.';
            }
        } catch (RequestException $exception) {
            $status = $exception->response?->status();
            $message = 'Kelviq returned HTTP '.($status ?? 'error').'.';
        } catch (ConnectionException) {
            $status = null;
            $message = 'Connection failure contacting Kelviq.';
        } catch (RuntimeException $exception) {
            $code = $exception->getMessage();
            if (str_starts_with($code, 'billing_kelviq_http_')) {
                $status = (int) substr($code, strlen('billing_kelviq_http_'));
                $message = 'Kelviq returned HTTP '.$status.'.';
            } else {
                $message = 'Connection failed ('.$code.').';
            }
        } catch (Throwable) {
            $message = 'Connection failed.';
        }

        $audit->append([
            'actor_type' => 'user',
            'actor_id' => (string) $request->user()->id,
            'event_type' => 'billing.settings.connection_tested',
            'subject_type' => BillingGatewaySetting::class,
            'subject_id' => '1',
            'metadata' => [
                'ok' => $ok,
                'status' => $status,
            ],
            'ip_address' => $request->ip(),
            'occurred_at' => now(),
        ]);

        return redirect()
            ->route('platform.billing.settings.edit')
            ->with('connection_test', [
                'ok' => $ok,
                'status' => $status,
                'message' => $message,
            ]);
    }
}
