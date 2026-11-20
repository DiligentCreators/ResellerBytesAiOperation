<?php

namespace App\Http\Responses;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class VerifyEmailResponse implements VerifyEmailResponseContract
{
    public function toResponse($request): Response
    {
        $user = $request->user();
        $destination = $this->destination($user);

        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->intended($destination);
    }

    private function destination(?User $user): string
    {
        if ($user instanceof User && $this->needsOnboarding($user)) {
            return route('onboarding.show').'?verified=1';
        }

        return Fortify::redirects('email-verification').'?verified=1';
    }

    private function needsOnboarding(User $user): bool
    {
        return $user->tenantMemberships()
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->where('role', TenantMembership::ROLE_OWNER)
            ->whereHas('tenant', function ($query): void {
                $query->where('status', Tenant::STATUS_ACTIVE)
                    ->whereNull('onboarding_completed_at');
            })
            ->exists();
    }
}
