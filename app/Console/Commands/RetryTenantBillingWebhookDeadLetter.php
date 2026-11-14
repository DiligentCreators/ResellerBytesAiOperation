<?php

namespace App\\Console\\Commands;

use App\\Services\\Tenancy\\TenantBillingWebhookRecoveryService;
use Illuminate\\Console\\Command;
use Illuminate\\Support\\Facades\\Log;
use Throwable;

class RetryTenantBillingWebhookDeadLetter extends Command
{
    protected $signature = 'billing:webhooks:retry-dead-letter {eventId} {--tenant=} {--actor=} {--reason=} {--confirm}';

    protected $description = 'Requeue a dead-lettered billing webhook with tenant authorization and audit evidence';

    public function handle(): int
    {
        $eventId = filter_var($this->argument('eventId'), FILTER_VALIDATE_INT);
        $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT);
        $tenantId = $this->option('tenant');
        $reason = $this->option('reason');

        if ($eventId === false || $eventId < 1 || $actorId === false || $actorId < 1
            || ! is_string($tenantId) || ! preg_match('/^[0-9a-fA-F-]{36}$/', $tenantId)
            || ! is_string($reason) || ! preg_match('/^[a-z][a-z0-9_.-]{2,79}$/', $reason)
            || ! $this->option('confirm')) {
            $this->error('Provide a valid tenant UUID, actor ID, reason code, and explicit --confirm.');

            return self::FAILURE;
        }

        try {
            $event = app(TenantBillingWebhookRecoveryService::class)->requeueDeadLetter(
                $tenantId,
                (int) $eventId,
                (int) $actorId,
                $reason,
            );
        } catch (Throwable $exception) {
            $this->error('The webhook event could not be requeued. Check its tenant, provider account, authorization, status, and replay limit.');
            Log::warning('Billing webhook dead-letter requeue rejected.', [
                'event_id' => (int) $eventId,
                'tenant_id' => $tenantId,
                'reason_code' => 'billing_webhook_manual_replay_failed',
                'exception_class' => $exception::class,
            ]);

            return self::FAILURE;
        }

        $this->info("Webhook event {$event->id} requeued (manual replay {$event->manual_replay_count}/3).");

        return self::SUCCESS;
    }
}
