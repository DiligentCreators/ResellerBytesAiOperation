<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreCheckoutRequest;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\PlanChangeClassifier;
use App\Services\Billing\ProrationCalculator;
use App\Services\Billing\SubscriptionManagementService;
use App\Services\Tenancy\TenantRoleAuthorization;
use App\Support\BillingCustomerMessages;
use App\Support\BillingDates;
use App\Support\CountryCodes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class CheckoutController extends Controller
{
    public function show(Request $request, Tenant $tenant, Plan $plan): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'billing.manage');
        abort_unless($plan->is_active && ! $plan->is_contact_sales, 404);

        $period = $request->string('period')->toString() === 'yearly' ? 'yearly' : 'monthly';
        $billing = (array) (($tenant->settings['billing'] ?? []) ?: []);

        $current = TenantSubscription::query()
            ->with(['plan:id,code,name,price_monthly_minor,price_yearly_minor', 'scheduledPlan:id,code,name'])
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['trialing', 'active', 'past_due'])
            ->orderByDesc('id')
            ->first();

        $classifier = app(PlanChangeClassifier::class);
        $isDowngrade = $current !== null && $classifier->isDowngrade($current, $plan, $period);
        $proration = null;
        $changeMode = 'new';
        $scheduledChange = $this->scheduledChangePayload($current);
        $scheduledChangeIsSameTarget = $scheduledChange !== null
            && (int) $scheduledChange['plan_id'] === (int) $plan->id
            && $scheduledChange['charge_period'] === $period;

        if ($current !== null) {
            $changeMode = $isDowngrade ? 'downgrade' : 'upgrade';
            if (! $isDowngrade) {
                $proration = app(ProrationCalculator::class)->forUpgrade(
                    $plan->priceForPeriod($period),
                    (int) $current->unit_amount_minor,
                    $current->current_period_starts_at,
                    $current->current_period_ends_at,
                    $plan->currency,
                )->toArray();
            }
        }

        return Inertia::render('billing/checkout', [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'plan' => [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $plan->description,
                'price_monthly_minor' => $plan->price_monthly_minor,
                'price_yearly_minor' => $plan->price_yearly_minor,
                'currency' => $plan->currency,
                'trial_days' => $plan->trial_days,
            ],
            'period' => $period,
            'countries' => CountryCodes::options(),
            'billingDetails' => [
                'company_name' => $billing['company_name'] ?? $tenant->name,
                'billing_email' => $billing['billing_email'] ?? $user->email,
                'country' => $billing['country'] ?? $tenant->country_code ?? 'US',
                'address_line1' => $billing['address_line1'] ?? '',
                'address_line2' => $billing['address_line2'] ?? '',
                'city' => $billing['city'] ?? '',
                'state' => $billing['state'] ?? '',
                'postal_code' => $billing['postal_code'] ?? '',
                'tax_id' => $billing['tax_id'] ?? '',
            ],
            'pricesArePlaceholders' => true,
            'changeMode' => $changeMode,
            'proration' => $proration,
            'currentPlan' => $current ? [
                'id' => $current->id,
                'plan_code' => $current->plan_code,
                'plan_name' => $current->plan->name ?? $current->plan_code,
                'billing_period' => $classifier->chargePeriodFor($current),
                'current_period_ends_at' => $current->current_period_ends_at?->toIso8601String(),
            ] : null,
            'scheduledChange' => $scheduledChange,
            'scheduledChangeIsSameTarget' => $scheduledChangeIsSameTarget,
            'scheduledChangeHumanDate' => $scheduledChangeIsSameTarget
                ? BillingDates::humanDate($scheduledChange['change_at'] ?? null, $tenant)
                : null,
            'timezone' => $tenant->timezone ?: 'UTC',
        ]);
    }

    public function store(StoreCheckoutRequest $request, Tenant $tenant, Plan $plan): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'billing.manage');
        abort_unless($plan->is_active && ! $plan->is_contact_sales, 404);

        $data = $request->validated();
        $billingDetails = [
            'company_name' => $data['company_name'],
            'billing_email' => $data['billing_email'],
            'country' => strtoupper($data['country']),
            'address_line1' => $data['address_line1'],
            'address_line2' => $data['address_line2'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'tax_id' => $data['tax_id'] ?? null,
        ];

        try {
            $current = TenantSubscription::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', ['trialing', 'active', 'past_due'])
                ->orderByDesc('id')
                ->first();

            if ($current !== null) {
                $result = app(SubscriptionManagementService::class)->changePlan(
                    $tenant->id,
                    (int) $current->id,
                    $user,
                    $plan,
                    $data['period'],
                    $billingDetails,
                );
            } else {
                $result = app(CheckoutService::class)->begin(
                    $tenant->id,
                    $user,
                    $plan,
                    $data['period'],
                    $billingDetails,
                );
                $result['mode'] = 'new';
            }
        } catch (AuthorizationException) {
            return back()->withErrors([
                'checkout' => BillingCustomerMessages::CHECKOUT_FORBIDDEN,
            ]);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return back()->withErrors([
                'checkout' => $this->checkoutErrorMessage($exception->getMessage()),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'checkout' => BillingCustomerMessages::CHECKOUT_GENERIC_FAILURE,
            ]);
        }

        if (($result['mode'] ?? null) === 'downgrade') {
            return redirect()
                ->route('billing.settings.show', $tenant)
                ->with('status', $this->scheduledChangeStatusMessage($tenant, $result));
        }

        if (($result['mode'] ?? null) === 'zero_upgrade') {
            return redirect()
                ->route('billing.settings.show', $tenant)
                ->with('status', 'Plan upgraded. No payment was due.');
        }

        $checkoutUrl = (string) ($result['checkout_url'] ?? '');
        abort_unless($this->checkoutUrlIsAllowed($checkoutUrl), 500, 'Checkout redirect was rejected.');

        return redirect()->away($checkoutUrl);
    }

    private function checkoutErrorMessage(string $code): string
    {
        return match ($code) {
            'billing_subscription_already_current' => BillingCustomerMessages::CHECKOUT_ALREADY_SUBSCRIBED,
            'billing_plan_unchanged' => BillingCustomerMessages::CHECKOUT_PLAN_UNCHANGED,
            'billing_scheduled_change_already_pending' => BillingCustomerMessages::CHECKOUT_SCHEDULED_CHANGE_ALREADY,
            'billing_plan_change_already_pending' => BillingCustomerMessages::CHECKOUT_PLAN_CHANGE_PENDING,
            'billing_plan_change_status_invalid', 'billing_plan_change_invalid',
            'billing_period_invalid', 'billing_plan_not_checkoutable',
            'billing_details_incomplete', 'billing_email_invalid', 'billing_country_invalid' => BillingCustomerMessages::CHECKOUT_GENERIC_FAILURE,
            default => BillingCustomerMessages::CHECKOUT_GENERIC_FAILURE,
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function scheduledChangeStatusMessage(Tenant $tenant, array $result): string
    {
        $scheduled = is_array($result['scheduled_change'] ?? null) ? $result['scheduled_change'] : [];
        $replaced = is_array($result['replaced_scheduled_change'] ?? null)
            ? $result['replaced_scheduled_change']
            : null;

        $planName = (string) ($scheduled['plan_name'] ?? 'plan');
        $period = (string) ($scheduled['charge_period'] ?? '');
        $when = BillingDates::humanDate($scheduled['change_at'] ?? null, $tenant);

        if ($replaced !== null) {
            $previousName = (string) ($replaced['plan_name'] ?? 'previous plan');
            $previousPeriod = (string) ($replaced['charge_period'] ?? '');
            $previousWhen = BillingDates::humanDate($replaced['change_at'] ?? null, $tenant);

            return sprintf(
                'Your scheduled change to %s (%s) on %s replaced the previous change to %s (%s)%s.',
                $planName,
                $period,
                $when,
                $previousName,
                $previousPeriod,
                $previousWhen !== '' ? ' on '.$previousWhen : '',
            );
        }

        return $when !== ''
            ? 'Plan change scheduled for '.$when.'.'
            : 'Plan change scheduled.';
    }

    /**
     * Only allow redirects to our own app (sandbox signed URLs) or Kelviq-hosted checkout pages.
     */
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

        $allowedSuffixes = [
            'kelviq.com',
            parse_url((string) config('billing.kelviq.sandbox_base_url'), PHP_URL_HOST),
            parse_url((string) config('billing.kelviq.live_base_url'), PHP_URL_HOST),
        ];

        foreach ($allowedSuffixes as $suffix) {
            if (! is_string($suffix) || $suffix === '') {
                continue;
            }
            if (strcasecmp($urlHost, $suffix) === 0 || Str::endsWith(strtolower($urlHost), '.'.strtolower($suffix))) {
                return true;
            }
        }

        // Sandbox temporary signed routes may use the current request host in tests.
        if (Str::endsWith(strtolower($urlHost), ['.test', 'localhost']) || $urlHost === '127.0.0.1') {
            return str_contains($url, '/billing/sandbox/');
        }

        return false;
    }

    /**
     * @return array{plan_id: int, plan_code: string, plan_name: string, charge_period: string, change_at: string}|null
     */
    private function scheduledChangePayload(?TenantSubscription $subscription): ?array
    {
        if ($subscription === null
            || $subscription->scheduled_plan_id === null
            || $subscription->scheduled_change_at === null
            || ! in_array($subscription->scheduled_charge_period, ['monthly', 'yearly'], true)) {
            return null;
        }

        $scheduledPlan = $subscription->scheduledPlan;

        return [
            'plan_id' => (int) $subscription->scheduled_plan_id,
            'plan_code' => $scheduledPlan->code ?? '',
            'plan_name' => $scheduledPlan->name ?? (string) $subscription->scheduled_plan_id,
            'charge_period' => $subscription->scheduled_charge_period,
            'change_at' => $subscription->scheduled_change_at->toIso8601String(),
        ];
    }
}
