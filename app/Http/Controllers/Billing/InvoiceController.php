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
                'service_period_starts_at' => $invoice->service_period_starts_at?->toIso8601String(),
                'service_period_ends_at' => $invoice->service_period_ends_at?->toIso8601String(),
            ]);

        return Inertia::render('billing/invoices/index', [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'invoices' => $invoices,
            'timezone' => $tenant->timezone ?: 'UTC',
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
            'timezone' => $tenant->timezone ?: 'UTC',
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
            'timezone' => $tenant->timezone ?: 'UTC',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(TenantBillingInvoice $invoice): array
    {
        $lineItems = $invoice->line_items ?? [];
        if ($lineItems === []) {
            $lineItems = [[
                'description' => 'Invoice '.$invoice->invoice_number,
                'quantity' => 1,
                'unit_amount_minor' => $invoice->amount_due_minor,
                'amount_minor' => $invoice->amount_due_minor,
            ]];
        }

        $totals = $this->totalsFromLineItems($invoice, $lineItems);

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'currency' => $invoice->currency,
            'subtotal_minor' => $totals['subtotal_minor'],
            'tax_minor' => $invoice->tax_minor,
            'amount_due_minor' => $invoice->amount_due_minor,
            'amount_paid_minor' => $invoice->amount_paid_minor,
            'line_items' => $lineItems,
            'credit_lines' => $totals['credit_lines'],
            'billing_details' => $invoice->billing_details ?? [],
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'due_at' => $invoice->due_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'service_period_starts_at' => $invoice->service_period_starts_at?->toIso8601String(),
            'service_period_ends_at' => $invoice->service_period_ends_at?->toIso8601String(),
        ];
    }

    /**
     * Subtotal is the sum of positive (charge) lines when credits exist; otherwise
     * Subtotal = Total − tax. Credit lines are surfaced for the totals block.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     * @return array{subtotal_minor: int, credit_lines: list<array{description: string, amount_minor: int}>}
     */
    private function totalsFromLineItems(TenantBillingInvoice $invoice, array $lineItems): array
    {
        $chargesSubtotal = 0;
        $creditLines = [];

        foreach ($lineItems as $item) {
            $amount = (int) ($item['amount_minor'] ?? 0);

            if ($amount > 0) {
                $chargesSubtotal += $amount;
            } elseif ($amount < 0) {
                $creditLines[] = [
                    'description' => (string) ($item['description'] ?? 'Credit'),
                    'amount_minor' => $amount,
                ];
            }
        }

        $subtotalMinor = $creditLines === []
            ? $invoice->amount_due_minor - $invoice->tax_minor
            : $chargesSubtotal;

        return [
            'subtotal_minor' => $subtotalMinor,
            'credit_lines' => $creditLines,
        ];
    }
}
