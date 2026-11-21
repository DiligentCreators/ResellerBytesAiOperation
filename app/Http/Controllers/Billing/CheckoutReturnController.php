<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantBillingInvoice;
use App\Models\User;
use App\Services\Tenancy\TenantRoleAuthorization;
use App\Support\BillingCustomerMessages;
use App\Support\BillingDates;
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

        $invoice->loadMissing('subscription');
        $details = is_array($invoice->billing_details) ? $invoice->billing_details : [];
        $isTrial = ($details['checkout_kind'] ?? null) === 'trial'
            || ((int) $invoice->amount_due_minor === 0
                && $invoice->subscription?->status === 'trialing');
        $trialDays = filter_var($details['trial_days'] ?? null, FILTER_VALIDATE_INT);
        if ($trialDays === false) {
            $trialDays = null;
        }

        $failed = in_array($invoice->status, ['failed', 'past_due', 'void'], true);
        $trialEndsAt = $invoice->subscription?->trial_ends_at;

        return Inertia::render('billing/checkout-return', [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->listStatus(),
                'amount_due_minor' => $invoice->amount_due_minor,
                'currency' => $invoice->currency,
            ],
            'isTrial' => $isTrial,
            'trialDays' => $trialDays,
            'trialEndsAt' => $trialEndsAt?->toIso8601String(),
            'trialEndsLabel' => $trialEndsAt !== null
                ? BillingDates::humanDate($trialEndsAt, $tenant)
                : null,
            'processing' => ! $failed && $invoice->status !== 'paid' && ! $isTrial,
            'declined' => $failed,
            'declineMessage' => $failed ? BillingCustomerMessages::PAYMENT_DECLINED : null,
            'successMessage' => $isTrial && ! $failed && $trialEndsAt !== null
                ? BillingCustomerMessages::trialStarted($trialEndsAt, $tenant)
                : null,
        ]);
    }
}
