<?php

namespace App\Contracts\Billing;

use App\Models\TenantBillingInvoice;
use App\Models\TenantSubscription;
use App\Models\User;

interface PaymentGateway
{
    public function driver(): string;

    /**
     * Start hosted checkout for an already-issued open invoice + provisional subscription.
     * Returns a relative or absolute URL the browser should visit.
     *
     * @param  array<string, mixed>  $billingDetails
     */
    public function startCheckout(
        TenantSubscription $subscription,
        TenantBillingInvoice $invoice,
        User $actor,
        array $billingDetails,
    ): string;

    public function assertUsable(): void;
}
