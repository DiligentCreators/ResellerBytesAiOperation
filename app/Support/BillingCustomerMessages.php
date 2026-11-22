<?php

namespace App\Support;

use App\Models\Tenant;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Customer-facing billing copy shared by sandbox and live gateway paths.
 */
final class BillingCustomerMessages
{
    public const PAYMENT_DECLINED = 'Your payment was declined. You haven\'t been charged — please try again or use a different payment method.';

    public const PAYMENT_APPROVED = 'Payment approved. Your subscription is active.';

    /**
     * Flash after a trial starts (no-card begin, sandbox approve, or Kelviq return).
     */
    public static function trialStarted(
        CarbonInterface|DateTimeInterface|string $endsAt,
        ?Tenant $tenant = null,
    ): string {
        return 'Trial started, ends '.BillingDates::humanDate($endsAt, $tenant).'.';
    }

    public const CHECKOUT_GENERIC_FAILURE = 'Checkout could not be started. Please check your billing details and try again.';

    public const CHECKOUT_ALREADY_SUBSCRIBED = 'This workspace already has an active subscription. Use Change plan from Billing settings to upgrade or switch period.';

    public const CHECKOUT_TRIAL_REQUIRES_PAID = 'Your workspace is on a free trial. Subscribe now to start a paid plan — trial time is not credited toward the paid period.';

    public const CHECKOUT_PLAN_UNCHANGED = 'You are already on this plan and billing period.';

    public const CHECKOUT_SCHEDULED_CHANGE_ALREADY = 'That plan change is already scheduled.';

    public const CHECKOUT_PLAN_CHANGE_PENDING = 'A plan change is already in progress. Finish or abandon that checkout before starting another.';

    public const CHECKOUT_FORBIDDEN = 'You do not have permission to manage billing for this workspace.';

    public const CHECKOUT_PLAN_NEEDS_PRICING = 'This plan is not available for purchase yet. A platform admin must set prices first.';
}
