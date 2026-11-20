<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantBillingInvoice;
use App\Models\TenantBillingPayment;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Billing\SubscriptionManagementService;
use App\Services\Tenancy\TenantRoleAuthorization;
use App\Support\CountryCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BillingSettingsController extends Controller
{
    public function show(Request $request, Tenant $tenant): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'billing.read');

        $subscription = TenantSubscription::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['trialing', 'active', 'past_due', 'suspended'])
            ->latest('id')
            ->first();

        $usage = [
            'members' => DB::table('tenant_memberships')->where('tenant_id', $tenant->id)->whereIn('status', ['active', 'invited', 'suspended'])->count(),
            'integrations' => DB::table('whmcs_integrations')->where('tenant_id', $tenant->id)->where('status', 'active')->count(),
            'diagnostics' => $subscription
                ? DB::table('diagnostic_runs')
                    ->where('tenant_id', $tenant->id)
                    ->where('received_at', '>=', $subscription->current_period_starts_at)
                    ->where('received_at', '<', $subscription->current_period_ends_at)
                    ->count()
                : 0,
        ];

        $invoices = TenantBillingInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->limit(20)
            ->get(['id', 'invoice_number', 'status', 'currency', 'amount_due_minor', 'amount_paid_minor', 'issued_at', 'paid_at']);

        $payments = TenantBillingPayment::query()
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->limit(20)
            ->get(['id', 'tenant_billing_invoice_id', 'status', 'currency', 'amount_minor', 'received_at', 'collection_mode']);

        $billing = (array) (($tenant->settings['billing'] ?? []) ?: []);
        $membership = app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'billing.read');
        $canManage = app(TenantRoleAuthorization::class)->allows($membership, 'billing.manage');

        return Inertia::render('billing/settings', [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'plan_code' => $subscription->plan_code,
                'status' => $subscription->status,
                'currency' => $subscription->currency,
                'unit_amount_minor' => $subscription->unit_amount_minor,
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'current_period_starts_at' => $subscription->current_period_starts_at?->toIso8601String(),
                'current_period_ends_at' => $subscription->current_period_ends_at?->toIso8601String(),
                'entitlements' => $subscription->entitlements,
            ] : null,
            'usage' => $usage,
            'invoices' => $invoices,
            'payments' => $payments,
            'plans' => Plan::query()->publicCatalog()->get(['id', 'code', 'name', 'price_monthly_minor', 'price_yearly_minor', 'currency', 'is_contact_sales']),
            'billingDetails' => $billing,
            'countries' => CountryCodes::options(),
            'canManage' => $canManage,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function updateDetails(Request $request, Tenant $tenant): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'billing.manage');

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:160'],
            'billing_email' => ['required', 'email:rfc', 'max:255'],
            'country' => ['required', 'string', 'size:2'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:40'],
            'tax_id' => ['nullable', 'string', 'max:80'],
        ]);

        $settings = (array) $tenant->settings;
        $settings['billing'] = [
            ...$data,
            'country' => strtoupper($data['country']),
        ];
        $tenant->forceFill(['settings' => $settings])->save();

        return back()->with('status', 'Billing details updated.');
    }

    public function cancel(Request $request, Tenant $tenant, TenantSubscription $subscription): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless($subscription->tenant_id === $tenant->id, 404);

        try {
            app(SubscriptionManagementService::class)->cancelAtPeriodEnd($tenant->id, (int) $subscription->id, $user);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['subscription' => 'Unable to schedule cancellation.']);
        }

        return back()->with('status', 'Subscription will cancel at period end.');
    }

    public function resume(Request $request, Tenant $tenant, TenantSubscription $subscription): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless($subscription->tenant_id === $tenant->id, 404);

        try {
            app(SubscriptionManagementService::class)->resume($tenant->id, (int) $subscription->id, $user);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['subscription' => 'Unable to resume subscription.']);
        }

        return back()->with('status', 'Cancellation at period end cleared.');
    }
}
