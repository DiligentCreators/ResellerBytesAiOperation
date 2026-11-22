<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Ensure catalog plans expose reports.value (Growth+ / Pro packaging).
     * Subscription snapshots stay immutable; TenantEntitlementService falls back
     * to the plan when a boolean feature key is absent from the snapshot.
     */
    public function up(): void
    {
        $byCode = [
            'starter' => false,
            'growth' => true,
            'business' => true,
            'enterprise' => true,
        ];

        foreach ($byCode as $code => $enabled) {
            $plan = Plan::query()->where('code', $code)->first();
            if ($plan === null) {
                continue;
            }

            $entitlements = (array) $plan->entitlements;
            if (array_key_exists('reports.value', $entitlements)) {
                continue;
            }

            $entitlements['reports.value'] = $enabled;
            $plan->forceFill(['entitlements' => $entitlements])->save();
        }
    }

    public function down(): void
    {
        // Leave catalog flags in place — removing them would hide Growth+
        // packaging without restoring prior subscription snapshot behavior.
    }
};
