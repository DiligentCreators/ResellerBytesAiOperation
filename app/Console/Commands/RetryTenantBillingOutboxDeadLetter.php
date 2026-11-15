<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenantBillingOutboxRecoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RetryTenantBillingOutboxDeadLetter extends Command
{
    protected $signature = 'billing:outbox:retry-dead-letter {messageId} {--tenant=} {--actor=} {--reason=} {--confirm}';

    protected $description = 'Requeue a dead-lettered billing outbox message with tenant authorization and audit evidence';

    public function handle(): int
    {
        $messageId = filter_var($this->argument('messageId'), FILTER_VALIDATE_INT);
        $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT);
        $tenantId = $this->option('tenant');
        $reason = $this->option('reason');

        if ($messageId === false || $messageId < 1 || $actorId === false || $actorId < 1
            || ! is_string($tenantId) || ! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $tenantId)
            || ! is_string($reason) || ! preg_match('/^[a-z][a-z0-9_.-]{2,79}$/', $reason)
            || ! $this->option('confirm')) {
            $this->error('Provide a valid tenant UUID, actor ID, reason code, and explicit --confirm.');

            return self::FAILURE;
        }

        try {
            $message = app(TenantBillingOutboxRecoveryService::class)->requeueDeadLetter(
                $tenantId,
                (int) $messageId,
                (int) $actorId,
                $reason,
            );
        } catch (Throwable $exception) {
            Log::warning('Billing outbox dead-letter requeue rejected.', [
                'message_id' => (int) $messageId,
                'tenant_id' => $tenantId,
                'reason_code' => 'billing_outbox_manual_replay_failed',
                'exception_class' => $exception::class,
            ]);

            $this->error('The outbox message could not be requeued. Check tenant authorization, message status, and replay limit.');

            return self::FAILURE;
        }

        $this->info("Outbox message {$message->id} requeued (manual replay {$message->manual_replay_count}/3).");

        return self::SUCCESS;
    }
}
