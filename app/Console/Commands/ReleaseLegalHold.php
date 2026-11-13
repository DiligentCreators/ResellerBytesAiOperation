<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LegalHoldService;
use Illuminate\Console\Command;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Throwable;

class ReleaseLegalHold extends Command
{
    protected $signature = 'legal-hold:release {hold : Legal hold UUID} {--reason=} {--evidence=}';

    protected $description = 'Release a legal hold with explicit Owner approval and an immutable audit event';

    public function handle(LegalHoldService $holds, TwoFactorAuthenticationProvider $totp): int
    {
        $ownerEmail = mb_strtolower(trim((string) config('operations.owner_email', '')));
        $owner = $ownerEmail !== '' ? User::query()->where('email', $ownerEmail)->first() : null;
        if ($owner === null) {
            $this->error('OPERATIONS_OWNER_EMAIL must identify an existing owner account.');

            return self::FAILURE;
        }

        try {
            $holds->assertOwner($owner);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $code = $this->secret('Enter the current 6-digit Owner TOTP code');
        if (! is_string($code) || preg_match('/^\d{6}$/D', trim($code)) !== 1 || ! $this->verifyTotp($owner, trim($code), $totp)) {
            $this->error('Fresh Owner TOTP verification failed. No changes made.');

            return self::FAILURE;
        }

        try {
            $hold = $holds->release(
                $owner,
                (string) $this->argument('hold'),
                (string) $this->option('reason'),
                (string) $this->option('evidence'),
            );
        } catch (Throwable $exception) {
            $this->error('Legal hold release failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Legal hold released and immutable audit event committed. Hold ID: '.$hold->id);

        return self::SUCCESS;
    }

    private function verifyTotp(User $owner, string $code, TwoFactorAuthenticationProvider $totp): bool
    {
        try {
            $secret = Fortify::currentEncrypter()->decrypt($owner->two_factor_secret);

            return $totp->verify($secret, $code);
        } catch (Throwable) {
            return false;
        }
    }
}
