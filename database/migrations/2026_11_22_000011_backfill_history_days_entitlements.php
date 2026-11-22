<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Ensure catalog plans expose limits.history_days for health trend retention.
     * Existing admin values win; missing keys are filled from the catalog defaults.
     */
    public function up(): void
    {
        $byCode = [
            'starter' => 90,
            'growth' => 365,
            'business' => 365,
            'enterprise' => 365,
        ];

        foreach ($byCode as $code => $days) {
            $plan = Plan::query()->where('code', $code)->first();
            if ($plan === null) {
                continue;
            }

            $entitlements = (array) $plan->entitlements;
            $limits = is_array($entitlements['limits'] ?? null) ? $entitlements['limits'] : [];
            if (array_key_exists('history_days', $limits)) {
                continue;
            }

            $limits['history_days'] = $days;
            $entitlements['limits'] = $limits;
            $plan->forceFill(['entitlements' => $entitlements])->save();
        }
    }

    public function down(): void
    {
        // Leave history_days in place — removing it would shrink Trends retention
        // without restoring prior subscription snapshot behavior.
    }
};
