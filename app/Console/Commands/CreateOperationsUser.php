<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateOperationsUser extends Command
{
    protected $signature = 'ops:user:create {name : The operator display name} {email : The operator email address}';

    protected $description = 'Create a verified operations dashboard user without public registration';

    public function handle(): int
    {
        $attributes = [
            'name' => trim((string) $this->argument('name')),
            'email' => mb_strtolower(trim((string) $this->argument('email'))),
        ];

        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $password = $this->secret('Password (minimum 14 characters)');
        $confirmation = $this->secret('Confirm password');

        if (! is_string($password) || ! is_string($confirmation) || ! hash_equals($password, $confirmation)) {
            $this->error('The password confirmation did not match.');

            return self::FAILURE;
        }

        $passwordValidator = Validator::make(
            ['password' => $password],
            ['password' => ['required', Password::min(14)->letters()->mixedCase()->numbers()->symbols()]],
        );

        if ($passwordValidator->fails()) {
            $this->error('The password does not meet the required strength policy.');

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($attributes, $password): User {
            $user = new User;
            $user->forceFill([
                ...$attributes,
                'password' => $password,
                'email_verified_at' => now(),
            ])->save();

            AuditEvent::create([
                'actor_type' => 'system_operator',
                'event_type' => 'operations_user.created',
                'subject_type' => 'user',
                'subject_id' => (string) $user->id,
                'metadata' => ['email' => $user->email],
                'occurred_at' => now(),
            ]);

            return $user;
        });

        $this->info('Operations user created and email marked as verified.');
        $this->line('User ID: '.$user->id);
        $this->line('Email: '.$user->email);
        $this->comment('Sign in, then configure two-factor authentication or a passkey before production use.');

        return self::SUCCESS;
    }
}
