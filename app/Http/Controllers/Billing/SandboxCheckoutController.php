<?php

namespace App\Http\Controllers\Billing;

use App\Billing\Sandbox\SandboxPaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantBillingInvoice;
use App\Models\User;
use App\Services\Tenancy\TenantRoleAuthorization;
use App\Support\BillingCustomerMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SandboxCheckoutController extends Controller
{
    public function show(Request $request, Tenant $tenant, TenantBillingInvoice $invoice): Response
    {
        abort_unless($request->hasValidSignature(), 403);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless($invoice->tenant_id === $tenant->id, 404);
        app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'billing.manage');
        app(SandboxPaymentGateway::class)->assertUsable();

        return Inertia::render('billing/sandbox-checkout', [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'currency' => $invoice->currency,
                'amount_due_minor' => $invoice->amount_due_minor,
                'status' => $invoice->status,
                'line_items' => $invoice->line_items,
            ],
            'approveUrl' => route('billing.sandbox.checkout.approve', [
                'tenant' => $tenant->id,
                'invoice' => $invoice->id,
            ]),
            'declineUrl' => route('billing.sandbox.checkout.decline', [
                'tenant' => $tenant->id,
                'invoice' => $invoice->id,
            ]),
        ]);
    }

    public function approve(Request $request, Tenant $tenant, TenantBillingInvoice $invoice): RedirectResponse
    {
        return $this->settle($request, $tenant, $invoice, true);
    }

    public function decline(Request $request, Tenant $tenant, TenantBillingInvoice $invoice): RedirectResponse
    {
        return $this->settle($request, $tenant, $invoice, false);
    }

    private function settle(Request $request, Tenant $tenant, TenantBillingInvoice $invoice, bool $approve): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless($invoice->tenant_id === $tenant->id, 404);
        app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'billing.manage');

        try {
            $gateway = app(SandboxPaymentGateway::class);
            if ($approve) {
                $gateway->approve($invoice);
            } else {
                $gateway->decline($invoice);
            }
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('billing.settings.show', $tenant)
                ->withErrors(['checkout' => 'Sandbox settlement failed.']);
        }

        if ($approve) {
            return redirect()
                ->route('billing.settings.show', $tenant)
                ->with('status', BillingCustomerMessages::PAYMENT_APPROVED);
        }

        return redirect()
            ->route('billing.settings.show', $tenant)
            ->withErrors(['checkout' => BillingCustomerMessages::PAYMENT_DECLINED]);
    }
}
