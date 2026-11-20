<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantBillingInvoice;
use App\Models\User;
use App\Services\Tenancy\TenantRoleAuthorization;
use App\Support\BillingSeller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request, Tenant $tenant): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'billing.read');

        $invoices = TenantBillingInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->paginate(25)
            ->through(fn (TenantBillingInvoice $invoice): array => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'currency' => $invoice->currency,
                'amount_due_minor' => $invoice->amount_due_minor,
                'amount_paid_minor' => $invoice->amount_paid_minor,
                'issued_at' => $invoice->issued_at?->toIso8601String(),
                'paid_at' => $invoice->paid_at?->toIso8601String(),
            ]);

        return Inertia::render('billing/invoices/index', [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'invoices' => $invoices,
        ]);
    }

    public function show(Request $request, Tenant $tenant, TenantBillingInvoice $invoice): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless($invoice->tenant_id === $tenant->id, 404);
        app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'billing.read');

        return Inertia::render('billing/invoices/show', [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'invoice' => $this->payload($invoice),
            'seller' => BillingSeller::details(),
            'print' => false,
        ]);
    }

    public function print(Request $request, Tenant $tenant, TenantBillingInvoice $invoice): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless($invoice->tenant_id === $tenant->id, 404);
        app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'billing.read');

        return Inertia::render('billing/invoices/print', [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'invoice' => $this->payload($invoice),
            'seller' => BillingSeller::details(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(TenantBillingInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'currency' => $invoice->currency,
            'subtotal_minor' => $invoice->subtotal_minor,
            'tax_minor' => $invoice->tax_minor,
            'amount_due_minor' => $invoice->amount_due_minor,
            'amount_paid_minor' => $invoice->amount_paid_minor,
            'line_items' => $invoice->line_items ?? [],
            'billing_details' => $invoice->billing_details ?? [],
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'due_at' => $invoice->due_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
        ];
    }
}
