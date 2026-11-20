<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantBillingInvoice;
use App\Models\User;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Success redirect after Kelviq hosted checkout. Does not activate anything —
 * activation waits for the signed webhook + reconcile path.
 */
class CheckoutReturnController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant, TenantBillingInvoice $invoice): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'billing.manage');
        abort_unless($invoice->tenant_id === $tenant->id, 404);

        return Inertia::render('billing/checkout-return', [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'amount_due_minor' => $invoice->amount_due_minor,
                'currency' => $invoice->currency,
            ],
            'processing' => $invoice->status !== 'paid',
        ]);
    }
}
