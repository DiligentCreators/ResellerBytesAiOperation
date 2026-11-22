<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Align Growth catalog packaging: reports.value is Pro-only.
     * Only that entitlement key is updated; prices and other keys stay untouched.
     */
    public function up(): void
    {
        $this->setReportsValueForGrowthCodes(false);
    }

    public function down(): void
    {
        $this->setReportsValueForGrowthCodes(true);
    }

    private function setReportsValueForGrowthCodes(bool $enabled): void
    {
        /** @var list<mixed> $codes */
        $codes = (array) config('plan_tiers.codes.growth', []);

        foreach ($codes as $code) {
            if (! is_string($code) || $code === '') {
                continue;
            }

            $plan = Plan::query()->where('code', $code)->first();
            if ($plan === null) {
                continue;
            }

            $entitlements = (array) $plan->entitlements;
            $entitlements['reports.value'] = $enabled;
            $plan->forceFill(['entitlements' => $entitlements])->save();
        }
    }
};
