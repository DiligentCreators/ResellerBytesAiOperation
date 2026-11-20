<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantBillingInvoice;
use App\Models\TenantBillingPayment;
use App\Models\TenantSubscription;
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
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->first();

        return Inertia::render('platform/billing/tenant', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'billing' => $tenant->settings['billing'] ?? null,
            ],
            'subscription' => $subscription,
            'invoices' => TenantBillingInvoice::query()->where('tenant_id', $tenant->id)->latest('id')->limit(50)->get(),
            'payments' => TenantBillingPayment::query()->where('tenant_id', $tenant->id)->latest('id')->limit(50)->get(),
            'revenue_paid_minor' => (int) TenantBillingPayment::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', 'succeeded')
                ->sum('amount_minor'),
        ]);
    }

    public function updateSeller(Request $request): RedirectResponse
    {
        $this->authorizeBilling($request);

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:500'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:80'],
        ]);

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
}
