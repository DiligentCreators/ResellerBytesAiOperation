<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Ensure Growth+ catalog plans expose Safe Actions. Subscription snapshots
     * stay immutable; TenantEntitlementService falls back to the plan when a
     * boolean feature key is absent from the snapshot.
     */
    public function up(): void
    {
        $enabledByCode = [
            'starter' => false,
            'growth' => true,
            'business' => true,
            'enterprise' => true,
        ];

        foreach ($enabledByCode as $code => $enabled) {
            $plan = Plan::query()->where('code', $code)->first();
            if ($plan === null) {
                continue;
            }

            $entitlements = (array) $plan->entitlements;
            $entitlements['safe_actions.enabled'] = $enabled;
            $plan->forceFill(['entitlements' => $entitlements])->save();
        }
    }

    public function down(): void
    {
        // Leave catalog flags in place — rolling them back would hide Safe Actions
        // from Growth+ packaging without restoring prior subscription behavior.
    }
};
