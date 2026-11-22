<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\UpdateBillingDetailsRequest;
use App\Models\DiagnosticFinding;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantBillingInvoice;
use App\Models\TenantBillingPayment;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Billing\PlanChangeClassifier;
use App\Services\Billing\SubscriptionManagementService;
use App\Services\Health\TimeSavedCalculator;
use App\Services\Tenancy\TenantAccess;
use App\Services\Tenancy\TenantRoleAuthorization;
use App\Support\CountryCodes;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
            ->with([
                'plan:id,code,name,price_monthly_minor,price_yearly_minor',
                'scheduledPlan:id,code,name',
            ])
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
            ->get([
                'id',
                'invoice_number',
                'status',
                'currency',
                'amount_due_minor',
                'amount_paid_minor',
                'billing_details',
                'issued_at',
                'paid_at',
                'service_period_starts_at',
                'service_period_ends_at',
            ]);

        $payments = TenantBillingPayment::query()
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->limit(20)
            ->get(['id', 'tenant_billing_invoice_id', 'status', 'currency', 'amount_minor', 'received_at', 'collection_mode']);

        $billing = (array) (($tenant->settings['billing'] ?? []) ?: []);
        $membership = app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'billing.read');
        $canManage = app(TenantRoleAuthorization::class)->allows($membership, 'billing.manage');
        $timezone = $tenant->timezone ?: 'UTC';

        $periodStart = $subscription?->current_period_starts_at
            ? CarbonImmutable::instance($subscription->current_period_starts_at)->utc()
            : now('UTC')->subDays(30);
        $periodEnd = now('UTC');
        $timeSaved = app(TimeSavedCalculator::class)->forTenantPeriod($tenant, $periodStart, $periodEnd);
        $issuesFixed = DiagnosticFinding::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', DiagnosticFinding::STATUS_RESOLVED)
            ->where('resolved_at', '>=', $periodStart)
            ->where('resolved_at', '<=', $periodEnd)
            ->count();
        $openCritical = DiagnosticFinding::query()
            ->where('tenant_id', $tenant->id)
            ->currentIssues()
            ->active()
            ->where('severity', 'critical')
            ->count();

        $cancelValueReminder = [
            'time_saved_hours_display' => $timeSaved['hours_display'],
            'time_saved_value_display' => $timeSaved['value_display'],
            'hourly_rate_usd' => $timeSaved['hourly_rate_usd'],
            'issues_fixed' => $issuesFixed,
            'open_critical' => $openCritical,
        ];

        return Inertia::render('billing/settings', [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'cancelValueReminder' => $cancelValueReminder,
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'plan_code' => $subscription->plan_code,
                'plan_name' => $subscription->plan->name ?? $subscription->plan_code,
                'status' => $subscription->status,
                'currency' => $subscription->currency,
                'unit_amount_minor' => $subscription->unit_amount_minor,
                'billing_period' => app(PlanChangeClassifier::class)->chargePeriodFor($subscription),
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'current_period_starts_at' => $subscription->current_period_starts_at?->toIso8601String(),
                'current_period_ends_at' => $subscription->current_period_ends_at?->toIso8601String(),
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'trial_days_remaining' => $subscription->status === 'trialing'
                    ? $subscription->trialDaysRemaining()
                    : null,
                'is_convertible_trial' => $subscription->isConvertibleTrial(),
                'entitlements' => $subscription->entitlements,
            ] : null,
            'scheduledChange' => $this->scheduledChangePayload($subscription),
            'timezone' => $timezone,
            'usage' => $usage,
            'invoices' => $invoices->map(fn (TenantBillingInvoice $invoice): array => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->listStatus(),
                'currency' => $invoice->currency,
                'amount_due_minor' => $invoice->amount_due_minor,
                'amount_paid_minor' => $invoice->amount_paid_minor,
                'issued_at' => $invoice->issued_at?->toIso8601String(),
                'paid_at' => $invoice->paid_at?->toIso8601String(),
                'service_period_starts_at' => $invoice->service_period_starts_at?->toIso8601String(),
                'service_period_ends_at' => $invoice->service_period_ends_at?->toIso8601String(),
            ]),
            'payments' => $payments->map(fn (TenantBillingPayment $payment): array => [
                'id' => $payment->id,
                'tenant_billing_invoice_id' => $payment->tenant_billing_invoice_id,
                'status' => $payment->status,
                'currency' => $payment->currency,
                'amount_minor' => $payment->amount_minor,
                'received_at' => $payment->received_at?->toIso8601String(),
                'collection_mode' => $payment->collection_mode,
            ]),
            'plans' => Plan::query()->publicCatalog()->get(['id', 'code', 'name', 'price_monthly_minor', 'price_yearly_minor', 'currency', 'is_contact_sales']),
            'billingDetails' => $billing,
            'countries' => CountryCodes::options(),
            'canManage' => $canManage,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function updateDetails(UpdateBillingDetailsRequest $request, Tenant $tenant): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'billing.manage');

        $data = $request->validated();

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
        app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'billing.manage');

        try {
            app(SubscriptionManagementService::class)->cancelAtPeriodEnd($tenant->id, (int) $subscription->id, $user);
        } catch (AuthorizationException $exception) {
            throw $exception;
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
        app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'billing.manage');

        try {
            app(SubscriptionManagementService::class)->resume($tenant->id, (int) $subscription->id, $user);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['subscription' => 'Unable to resume subscription.']);
        }

        return back()->with('status', 'Cancellation at period end cleared.');
    }

    public function cancelScheduledChange(Request $request, Tenant $tenant, TenantSubscription $subscription): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        // Authorize membership before any other work so outsiders always get 404.
        try {
            app(TenantAccess::class)->requireMembership($user, $tenant->id);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        abort_unless($subscription->tenant_id === $tenant->id, 404);
        app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'billing.manage');

        try {
            app(SubscriptionManagementService::class)->cancelScheduledChange($tenant->id, (int) $subscription->id, $user);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['subscription' => 'Unable to cancel the scheduled plan change.']);
        }

        return back()->with('status', 'Scheduled plan change canceled.');
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
