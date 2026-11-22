<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Ensure catalog plans expose fixes.guided / fixes.one_click / fixes.bulk.
     * Subscription snapshots stay immutable; TenantEntitlementService falls back
     * to the plan when a boolean feature key is absent from the snapshot.
     */
    public function up(): void
    {
        $byCode = [
            'starter' => [
                'fixes.guided' => true,
                'fixes.one_click' => false,
                'fixes.bulk' => false,
            ],
            'growth' => [
                'fixes.guided' => true,
                'fixes.one_click' => true,
                'fixes.bulk' => true,
            ],
            'business' => [
                'fixes.guided' => true,
                'fixes.one_click' => true,
                'fixes.bulk' => true,
            ],
            'enterprise' => [
                'fixes.guided' => true,
                'fixes.one_click' => true,
                'fixes.bulk' => true,
            ],
        ];

        foreach ($byCode as $code => $flags) {
            $plan = Plan::query()->where('code', $code)->first();
            if ($plan === null) {
                continue;
            }

            $entitlements = (array) $plan->entitlements;
            foreach ($flags as $key => $enabled) {
                if (! array_key_exists($key, $entitlements)) {
                    $entitlements[$key] = $enabled;
                }
            }
            $plan->forceFill(['entitlements' => $entitlements])->save();
        }
    }

    public function down(): void
    {
        // Leave catalog flags in place — rolling them back would hide Growth+
        // packaging without restoring prior subscription behavior.
    }
};
