<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateBillingSellerRequest;
use App\Models\Tenant;
use App\Models\TenantBillingInvoice;
use App\Models\TenantBillingPayment;
use App\Models\TenantSubscription;
use App\Services\Billing\PlanChangeClassifier;
use App\Services\CentralStaffAuthorization;
use App\Support\BillingSeller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PlatformBillingController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeBilling($request);

        $activeSubs = TenantSubscription::query()
            ->whereIn('status', ['trialing', 'active', 'past_due', 'suspended'])
            ->count();

        $mrrMinor = (int) TenantSubscription::query()
            ->whereIn('status', ['trialing', 'active', 'past_due'])
            ->sum('unit_amount_minor');

        $failedPayments = TenantBillingInvoice::query()
            ->whereIn('status', ['failed', 'past_due'])
            ->count();

        $subscriptions = TenantSubscription::query()
            ->with('tenant:id,name,slug')
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->limit(50)
            ->get();

        $invoices = TenantBillingInvoice::query()
            ->with('tenant:id,name,slug')
            ->when($request->string('invoice_status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->limit(50)
            ->get();

        return Inertia::render('platform/billing/index', [
            'summary' => [
                'mrr_minor' => $mrrMinor,
                'active_subscriptions' => $activeSubs,
                'failed_payments' => $failedPayments,
                'paid_invoices' => TenantBillingInvoice::query()->where('status', 'paid')->count(),
            ],
            'subscriptions' => $subscriptions,
            'invoices' => $invoices,
            'filters' => [
                'status' => $request->string('status')->toString(),
                'invoice_status' => $request->string('invoice_status')->toString(),
            ],
            'pricesArePlaceholders' => true,
            'seller' => BillingSeller::details(),
        ]);
    }

    public function tenant(Request $request, Tenant $tenant): Response
    {
        $this->authorizeBilling($request);

        $subscription = TenantSubscription::query()
            ->with([
                'plan:id,code,name,price_monthly_minor,price_yearly_minor',
                'scheduledPlan:id,code,name',
            ])
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->first();

        $billing = (array) (($tenant->settings['billing'] ?? []) ?: []);
        $classifier = app(PlanChangeClassifier::class);
        $billingPeriod = $subscription !== null ? $classifier->chargePeriodFor($subscription) : null;

        return Inertia::render('platform/billing/tenant', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone ?: 'UTC',
            ],
            'billingDetails' => [
                'company_name' => $this->nullableString($billing['company_name'] ?? null),
                'billing_email' => $this->nullableString($billing['billing_email'] ?? null),
                'country' => $this->nullableString($billing['country'] ?? null),
                'address_line1' => $this->nullableString($billing['address_line1'] ?? null),
                'address_line2' => $this->nullableString($billing['address_line2'] ?? null),
                'city' => $this->nullableString($billing['city'] ?? null),
                'postal_code' => $this->nullableString($billing['postal_code'] ?? null),
                'tax_id' => $this->nullableString($billing['tax_id'] ?? null),
            ],
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'plan_code' => $subscription->plan_code,
                'plan_name' => $subscription->plan->name ?? $subscription->plan_code,
                'status' => $subscription->status,
                'unit_amount_minor' => $subscription->unit_amount_minor,
                'currency' => $subscription->currency,
                'billing_period' => $billingPeriod,
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'billing_provider' => $subscription->billing_provider,
                'billing_mode' => $subscription->billing_mode,
                'current_period_starts_at' => $subscription->current_period_starts_at?->toIso8601String(),
                'current_period_ends_at' => $subscription->current_period_ends_at?->toIso8601String(),
            ] : null,
            'scheduledChange' => $this->scheduledChangePayload($subscription),
            'invoices' => TenantBillingInvoice::query()->where('tenant_id', $tenant->id)->latest('id')->limit(50)->get(),
            'payments' => TenantBillingPayment::query()->where('tenant_id', $tenant->id)->latest('id')->limit(50)->get(),
            'revenue_paid_minor' => (int) TenantBillingPayment::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', 'succeeded')
                ->sum('amount_minor'),
        ]);
    }

    public function updateSeller(UpdateBillingSellerRequest $request): RedirectResponse
    {
        $this->authorizeBilling($request);

        $data = $request->validated();

        // Persist seller overrides in platform mail-style singleton table is overkill;
        // store in cache/config file via DB platform settings key.
        DB::table('platform_billing_settings')->updateOrInsert(
            ['id' => 1],
            [
                'seller' => json_encode($data, JSON_THROW_ON_ERROR),
                'updated_at' => now('UTC'),
                'created_at' => now('UTC'),
            ],
        );

        return back()->with('status', 'Seller details saved.');
    }

    private function authorizeBilling(Request $request): void
    {
        abort_unless(app(CentralStaffAuthorization::class)->can($request->user(), 'billing'), 403);
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

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
