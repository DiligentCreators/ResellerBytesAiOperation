<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreTrialRequest;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\TrialService;
use App\Services\Tenancy\TenantRoleAuthorization;
use App\Support\BillingCustomerMessages;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class TrialController extends Controller
{
    public function store(StoreTrialRequest $request, Tenant $tenant, Plan $plan): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'billing.manage');
        abort_unless($plan->isCheckoutable(), 404);

        $data = $request->validated();
        $billingDetails = [
            'company_name' => $data['company_name'] ?? '',
            'billing_email' => $data['billing_email'] ?? '',
            'country' => strtoupper((string) ($data['country'] ?? '')),
            'address_line1' => $data['address_line1'] ?? '',
            'address_line2' => $data['address_line2'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'tax_id' => $data['tax_id'] ?? null,
        ];

        try {
            $result = app(TrialService::class)->begin(
                $tenant->id,
                $user,
                $plan,
                $data['period'],
                $billingDetails,
            );
        } catch (AuthorizationException) {
            return back()->withErrors([
                'trial' => BillingCustomerMessages::CHECKOUT_FORBIDDEN,
            ]);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return back()->withErrors([
                'trial' => $this->trialErrorMessage($exception->getMessage()),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'trial' => BillingCustomerMessages::CHECKOUT_GENERIC_FAILURE,
            ]);
        }

        if (! $result['card_required']) {
            return redirect()
                ->route('billing.settings.show', $tenant)
                ->with('status', 'Your free trial has started.');
        }

        $checkoutUrl = (string) ($result['checkout_url'] ?? '');
        abort_unless($this->checkoutUrlIsAllowed($checkoutUrl), 500, 'Trial checkout redirect was rejected.');

        return redirect()->away($checkoutUrl);
    }

    private function trialErrorMessage(string $code): string
    {
        return match ($code) {
            'billing_trial_not_eligible' => 'This workspace is not eligible for a free trial on this plan.',
            'billing_interval_inactive' => 'That billing interval is not available for this plan.',
            'billing_subscription_already_current' => BillingCustomerMessages::CHECKOUT_ALREADY_SUBSCRIBED,
            'billing_plan_not_checkoutable', 'billing_period_invalid',
            'billing_details_incomplete', 'billing_email_invalid', 'billing_country_invalid' => BillingCustomerMessages::CHECKOUT_GENERIC_FAILURE,
            default => BillingCustomerMessages::CHECKOUT_GENERIC_FAILURE,
        };
    }

    private function checkoutUrlIsAllowed(string $url): bool
    {
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $urlHost = parse_url($url, PHP_URL_HOST);
        if (! is_string($urlHost) || $urlHost === '') {
            return false;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && strcasecmp($appHost, $urlHost) === 0) {
            return true;
        }

        $billingSettings = app(BillingSettings::class);
        $allowedSuffixes = [
            'kelviq.com',
            parse_url($billingSettings->kelviqSandboxBaseUrl(), PHP_URL_HOST),
            parse_url($billingSettings->kelviqLiveBaseUrl(), PHP_URL_HOST),
        ];

        foreach ($allowedSuffixes as $suffix) {
            if (! is_string($suffix) || $suffix === '') {
                continue;
            }
            if (strcasecmp($urlHost, $suffix) === 0 || Str::endsWith(strtolower($urlHost), '.'.strtolower($suffix))) {
                return true;
            }
        }

        if (Str::endsWith(strtolower($urlHost), ['.test', 'localhost']) || $urlHost === '127.0.0.1') {
            return str_contains($url, '/billing/sandbox/');
        }

        return false;
    }
}
