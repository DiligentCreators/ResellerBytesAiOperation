<?php

namespace App\Billing\Kelviq;

use App\Contracts\Billing\BillingProviderAdapter;
use App\Data\Billing\VerifiedBillingWebhook;
use App\Models\TenantBillingInvoice;
use App\Models\TenantBillingPayment;
use App\Models\TenantSubscription;
use App\Services\Billing\BillingSettings;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Verifies Kelviq webhook signatures and maps documented event types to the
 * local billing inbox contract. Unknown events become webhook.ignored.
 */
class KelviqBillingProviderAdapter implements BillingProviderAdapter
{
    public function __construct(private readonly KelviqWebhookSignature $signatures) {}

    public function provider(): string
    {
        return 'kelviq';
    }

    /**
     * @param  array<string, string|string[]>  $headers
     */
    public function verifyAndNormalize(string $rawBody, array $headers): VerifiedBillingWebhook
    {
        $secret = app(BillingSettings::class)->kelviqWebhookSecret();
        $timestamp = $this->signatures->assertValid($rawBody, $headers, $secret);

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('billing_kelviq_payload_invalid', previous: $exception);
        }

        $eventId = $payload['id'] ?? null;
        $kelviqType = $payload['type'] ?? null;
        $createdAt = $payload['created_at'] ?? null;
        $object = is_array($payload['data'] ?? null) && is_array($payload['data']['object'] ?? null)
            ? $payload['data']['object']
            : null;

        if (! is_string($eventId) || $eventId === '' || ! is_string($kelviqType) || $kelviqType === ''
            || ! is_string($createdAt) || ! is_array($object)) {
            throw new InvalidArgumentException('billing_kelviq_payload_fields_invalid');
        }

        if (($payload['test'] ?? false) === true) {
            return $this->ignored($eventId, $this->customerId($object), $timestamp, $createdAt, $kelviqType);
        }

        $providerAccountId = $this->customerId($object);
        [$eventType, $normalized] = $this->mapEvent($kelviqType, $object, $payload);

        return new VerifiedBillingWebhook(
            $providerAccountId,
            $eventId,
            $eventType,
            $normalized,
            CarbonImmutable::createFromTimestampUTC($timestamp),
            CarbonImmutable::parse($createdAt)->utc(),
        );
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function mapEvent(string $kelviqType, array $object, array $payload): array
    {
        return match ($kelviqType) {
            'invoice.paid' => $this->mapInvoicePaid($object),
            'invoice.payment_failed' => $this->mapInvoiceFailed($object),
            'invoice.created' => $this->mapInvoiceMirror($object),
            'subscription.cancelled' => $this->mapSubscriptionStatus($object, 'subscription.canceled'),
            'subscription.past_due' => $this->mapSubscriptionStatus($object, 'subscription.past_due'),
            'subscription.updated' => $this->mapSubscriptionUpdated($object),
            'subscription.paused' => $this->mapSubscriptionStatus($object, 'subscription.paused'),
            'subscription.resumed' => $this->mapSubscriptionStatus($object, 'subscription.resumed'),
            'subscription.created' => $this->mapSubscriptionCreated($object, $payload),
            'checkout.completed' => $this->mapCheckoutCompleted($object),
            'subscription.plan_changed' => $this->ignoredPayload($kelviqType),
            'refund.created', 'refund.updated', 'order.refunded' => $this->mapRefund($object, $kelviqType),
            'customer.created', 'customer.updated', 'order.created', 'order.updated',
            'payment_method.created', 'payment_method.updated', 'payment_method.deleted',
            'subscription.trial_will_end', 'feature.usage_alert' => $this->ignoredPayload($kelviqType),
            default => $this->ignoredPayload($kelviqType),
        };
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function mapInvoicePaid(array $object): array
    {
        $invoice = $this->resolveLocalInvoice($object);
        $amount = filter_var($object['amount_total_units'] ?? null, FILTER_VALIDATE_INT);
        $currency = strtoupper((string) ($object['currency'] ?? ''));
        $paymentId = is_string($object['id'] ?? null) ? (string) $object['id'] : null;

        if ($invoice === null || $amount === false || $amount < 1 || ! preg_match('/^[A-Z]{3}$/', $currency) || $paymentId === null) {
            return $this->ignoredPayload('invoice.paid');
        }

        return ['invoice.payment_succeeded', [
            'provider_invoice_id' => (string) $invoice->provider_invoice_id,
            'payment_id' => $paymentId,
            'amount_minor' => $amount,
            'currency' => $currency,
        ]];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function mapInvoiceFailed(array $object): array
    {
        $invoice = $this->resolveLocalInvoice($object);
        $code = data_get($object, 'failure_details.code', 'payment_failed');
        if ($invoice === null || ! is_string($code) || ! preg_match('/^[a-zA-Z0-9_.-]{1,80}$/', $code)) {
            return $this->ignoredPayload('invoice.payment_failed');
        }

        return ['invoice.payment_failed', [
            'provider_invoice_id' => (string) $invoice->provider_invoice_id,
            'failure_code' => $code,
        ]];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function mapInvoiceMirror(array $object): array
    {
        $invoice = $this->resolveLocalInvoice($object);
        $amount = filter_var($object['amount_total_units'] ?? null, FILTER_VALIDATE_INT);
        $currency = strtoupper((string) ($object['currency'] ?? ''));
        $remoteId = is_string($object['id'] ?? null) ? (string) $object['id'] : '';
        $status = is_string($object['status'] ?? null) ? (string) $object['status'] : 'OPEN';
        $paymentLink = is_string($object['payment_link'] ?? null) ? (string) $object['payment_link'] : '';

        if ($invoice === null || $amount === false || $amount < 0 || ! preg_match('/^[A-Z]{3}$/', $currency) || $remoteId === '') {
            return $this->ignoredPayload('invoice.created');
        }

        return ['invoice.mirrored', [
            'provider_invoice_id' => (string) $invoice->provider_invoice_id,
            'remote_invoice_id' => $remoteId,
            'amount_minor' => $amount,
            'currency' => $currency,
            'status' => substr($status, 0, 40),
            'payment_link' => substr($paymentLink, 0, 500),
        ]];
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function mapSubscriptionCreated(array $object, array $payload): array
    {
        $providerSubscriptionId = is_string($object['id'] ?? null) ? (string) $object['id'] : null;
        $providerAccountId = $this->customerId($object);
        if ($providerSubscriptionId === null) {
            return $this->ignoredPayload('subscription.created');
        }

        $local = $this->findProvisionalSubscription($providerAccountId);
        if ($local === null) {
            return $this->ignoredPayload('subscription.created');
        }

        if ($local->provider_subscription_id === null) {
            $local->forceFill(['provider_subscription_id' => $providerSubscriptionId])->save();
        } elseif ($local->provider_subscription_id !== $providerSubscriptionId) {
            return $this->ignoredPayload('subscription.created');
        }

        // Activation still waits for invoice.payment_succeeded (ledger path).
        return ['webhook.ignored', [
            'kelviq_type' => 'subscription.created',
            'note' => 'linked',
        ]];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function mapCheckoutCompleted(array $object): array
    {
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $providerInvoiceId = is_string($metadata['provider_invoice_id'] ?? null)
            ? (string) $metadata['provider_invoice_id']
            : null;

        if ($providerInvoiceId !== null) {
            $invoice = TenantBillingInvoice::query()
                ->where('provider', 'kelviq')
                ->where('provider_invoice_id', $providerInvoiceId)
                ->first();

            if ($invoice !== null) {
                $details = is_array($invoice->billing_details) ? $invoice->billing_details : [];
                $details['kelviq_checkout_completed'] = true;
                if (is_string($object['id'] ?? null)) {
                    $details['kelviq_checkout_session_id'] = (string) $object['id'];
                }
                $invoice->forceFill(['billing_details' => $details])->save();
            }
        }

        // Do not activate on redirect/checkout alone; invoice.paid drives ledger activation.
        return ['webhook.ignored', [
            'kelviq_type' => 'checkout.completed',
            'note' => 'correlated',
        ]];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function mapSubscriptionUpdated(array $object): array
    {
        $status = strtolower((string) ($object['status'] ?? ''));
        if ($status === 'past_due') {
            return $this->mapSubscriptionStatus($object, 'subscription.past_due');
        }

        if (in_array($status, ['canceled', 'cancelled'], true)) {
            return $this->mapSubscriptionStatus($object, 'subscription.canceled');
        }

        return $this->ignoredPayload('subscription.updated');
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function mapSubscriptionStatus(array $object, string $eventType): array
    {
        $providerSubscriptionId = is_string($object['id'] ?? null) ? (string) $object['id'] : null;
        if ($providerSubscriptionId === null) {
            return $this->ignoredPayload($eventType);
        }

        return [$eventType, ['provider_subscription_id' => $providerSubscriptionId]];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function mapRefund(array $object, string $kelviqType): array
    {
        $amount = filter_var($object['amount_units'] ?? null, FILTER_VALIDATE_INT);
        $currency = strtoupper((string) ($object['currency'] ?? ''));
        $adjustmentId = is_string($object['id'] ?? null) ? (string) $object['id'] : ('ord_'.(string) ($object['id'] ?? uniqid('rf_', true)));
        $providerAccountId = $this->customerId($object);

        if ($amount === false || $amount < 1 || ! preg_match('/^[A-Z]{3}$/', $currency)) {
            return $this->ignoredPayload($kelviqType);
        }

        $payment = TenantBillingPayment::query()
            ->where('provider', 'kelviq')
            ->where('provider_account_id', $providerAccountId)
            ->where('status', 'succeeded')
            ->latest('id')
            ->first();

        if ($payment === null || ! is_string($payment->provider_payment_id) || $payment->provider_payment_id === '') {
            return $this->ignoredPayload($kelviqType);
        }

        return ['payment.refunded', [
            'payment_id' => $payment->provider_payment_id,
            'adjustment_id' => substr($adjustmentId, 0, 180),
            'amount_minor' => $amount,
            'currency' => $currency,
        ]];
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function resolveLocalInvoice(array $object): ?TenantBillingInvoice
    {
        $providerAccountId = $this->customerId($object);
        $providerSubscriptionId = is_string($object['subscription_id'] ?? null)
            ? (string) $object['subscription_id']
            : null;

        if ($providerSubscriptionId !== null) {
            $subscription = TenantSubscription::query()
                ->where('billing_provider', 'kelviq')
                ->where('provider_account_id', $providerAccountId)
                ->where('provider_subscription_id', $providerSubscriptionId)
                ->first();

            if ($subscription !== null) {
                $invoice = TenantBillingInvoice::query()
                    ->where('tenant_id', $subscription->tenant_id)
                    ->where('tenant_subscription_id', $subscription->id)
                    ->where('provider', 'kelviq')
                    ->whereIn('status', ['open', 'past_due', 'failed'])
                    ->latest('id')
                    ->first();

                if ($invoice !== null) {
                    return $invoice;
                }
            }
        }

        return TenantBillingInvoice::query()
            ->where('provider', 'kelviq')
            ->where('provider_account_id', $providerAccountId)
            ->whereIn('status', ['open', 'past_due'])
            ->latest('id')
            ->first();
    }

    private function findProvisionalSubscription(string $providerAccountId): ?TenantSubscription
    {
        return TenantSubscription::query()
            ->where('billing_provider', 'kelviq')
            ->where('provider_account_id', $providerAccountId)
            ->whereIn('status', ['trialing', 'incomplete'])
            ->where(function ($query): void {
                $query->whereNull('provider_subscription_id')
                    ->orWhere('provider_subscription_id', 'like', 'sub_kelviq_pending_%');
            })
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function customerId(array $object): string
    {
        // Prefer the merchant-defined customerId we sent at checkout.
        $fromCustomer = data_get($object, 'customer.customer_id');
        if (is_string($fromCustomer) && $fromCustomer !== '') {
            return $fromCustomer;
        }

        $metadataTenant = data_get($object, 'metadata.tenant_id');
        if (is_string($metadataTenant) && $metadataTenant !== '') {
            return 'acct_kelviq_'.str_replace('-', '', $metadataTenant);
        }

        $direct = $object['customer_id'] ?? null;
        if (is_string($direct) && str_starts_with($direct, 'acct_')) {
            return $direct;
        }

        throw new RuntimeException('billing_kelviq_customer_id_missing');
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function ignoredPayload(string $kelviqType): array
    {
        return ['webhook.ignored', [
            'kelviq_type' => substr($kelviqType, 0, 80),
            'note' => 'unmapped',
        ]];
    }

    private function ignored(
        string $eventId,
        string $providerAccountId,
        int $timestamp,
        string $createdAt,
        string $kelviqType,
    ): VerifiedBillingWebhook {
        return new VerifiedBillingWebhook(
            $providerAccountId,
            $eventId,
            'webhook.ignored',
            ['kelviq_type' => substr($kelviqType, 0, 80), 'note' => 'test'],
            CarbonImmutable::createFromTimestampUTC($timestamp),
            CarbonImmutable::parse($createdAt)->utc(),
        );
    }
}
