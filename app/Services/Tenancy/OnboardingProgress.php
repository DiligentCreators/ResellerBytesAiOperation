<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\WhmcsIntegration;

class OnboardingProgress
{
    /**
     * Derive onboarding step completion from real tenant state, persist
     * onboarding_completed_at when every step is done, and return checklist data.
     *
     * @return array{needed: bool, tenant_id: string, steps: array{workspace: bool, plan: bool, whmcs: bool}, current_step: string}
     */
    public function forTenant(Tenant $tenant): array
    {
        if ($tenant->onboarding_completed_at !== null) {
            return [
                'needed' => false,
                'tenant_id' => (string) $tenant->id,
                'steps' => [
                    'workspace' => true,
                    'plan' => true,
                    'whmcs' => true,
                ],
                'current_step' => 'done',
            ];
        }

        $steps = $this->deriveSteps($tenant);
        $this->persistDerivedFlags($tenant, $steps);

        if ($steps['workspace'] && $steps['plan'] && $steps['whmcs']) {
            $tenant->forceFill(['onboarding_completed_at' => now()])->save();

            return [
                'needed' => false,
                'tenant_id' => (string) $tenant->id,
                'steps' => $steps,
                'current_step' => 'done',
            ];
        }

        return [
            'needed' => true,
            'tenant_id' => (string) $tenant->id,
            'steps' => $steps,
            'current_step' => $this->currentStep($steps),
        ];
    }

    /**
     * @return array{workspace: bool, plan: bool, whmcs: bool}
     */
    public function deriveSteps(Tenant $tenant): array
    {
        $onboarding = (array) data_get($tenant->settings, 'onboarding', []);

        $workspaceSaved = filled($onboarding['workspace_completed_at'] ?? null)
            || (
                filled($tenant->name)
                && filled($tenant->timezone)
                && filled($tenant->country_code)
            );

        $hasSubscription = TenantSubscription::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'trialing'])
            ->exists();

        $planDone = filled($onboarding['plan_completed_at'] ?? null) || $hasSubscription;

        $hasIntegration = WhmcsIntegration::query()
            ->where('tenant_id', $tenant->id)
            ->exists();

        $whmcsDone = filled($onboarding['whmcs_completed_at'] ?? null) || $hasIntegration;

        return [
            'workspace' => $workspaceSaved,
            'plan' => $planDone,
            'whmcs' => $whmcsDone,
        ];
    }

    /**
     * @param  array{workspace: bool, plan: bool, whmcs: bool}  $steps
     */
    private function persistDerivedFlags(Tenant $tenant, array $steps): void
    {
        $settings = (array) $tenant->settings;
        $onboarding = (array) ($settings['onboarding'] ?? []);
        $dirty = false;
        $now = now()->toIso8601String();

        foreach (['workspace', 'plan', 'whmcs'] as $step) {
            $key = $step.'_completed_at';

            if ($steps[$step] && ! filled($onboarding[$key] ?? null)) {
                $onboarding[$key] = $now;
                $dirty = true;
            }
        }

        if (! $dirty) {
            return;
        }

        $settings['onboarding'] = $onboarding;
        $tenant->forceFill(['settings' => $settings])->save();
    }

    /**
     * @param  array{workspace: bool, plan: bool, whmcs: bool}  $steps
     */
    private function currentStep(array $steps): string
    {
        if (! $steps['workspace']) {
            return 'workspace';
        }

        if (! $steps['plan']) {
            return 'plan';
        }

        if (! $steps['whmcs']) {
            return 'whmcs';
        }

        return 'done';
    }
}
