<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\CountryCodes;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly TenantProvisioningService $tenants) {}

    /**
     * Validate and create a newly registered user with their owner workspace.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        $this->ensureIsNotRateLimited();

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->registrationPasswordRules(),
            'workspace_name' => ['required', 'string', 'min:2', 'max:120'],
            'country' => ['required', 'string', 'size:2', Rule::in(CountryCodes::codes())],
            'terms' => ['accepted'],
            // Honeypot: legitimate browsers leave this empty; bots that fill it are rejected.
            'website' => ['nullable', 'string', 'max:0'],
        ], [
            'website.max' => 'Registration could not be completed.',
            'terms.accepted' => 'You must accept the terms of service to create an account.',
        ])->validate();

        $user = DB::transaction(function () use ($input): User {
            $user = User::query()->create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $this->tenants->provisionOwnerWorkspace($user, [
                'name' => $input['workspace_name'],
                'country_code' => strtoupper((string) $input['country']),
                'timezone' => 'UTC',
            ], request()->ip());

            return $user;
        });

        RateLimiter::hit($this->throttleKey(), 60);

        return $user;
    }

    /**
     * @return array<int, mixed>
     */
    private function registrationPasswordRules(): array
    {
        return [
            'required',
            'string',
            'confirmed',
            Password::min(12)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols(),
        ];
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        throw new ThrottleRequestsException('Too many registration attempts. Please try again shortly.');
    }

    private function throttleKey(): string
    {
        return 'register|'.(string) request()->ip();
    }
}
