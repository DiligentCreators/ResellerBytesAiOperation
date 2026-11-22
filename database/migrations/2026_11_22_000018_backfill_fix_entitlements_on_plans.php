<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Ensure catalog plans expose fix / alert / report entitlement flags and
     * limits.history_days from config/plan_tiers.php. Missing keys are filled;
     * existing admin values and all price columns are left untouched.
     */
    public function up(): void
    {
        /** @var array<string, list<string>> $codesByTier */
        $codesByTier = (array) config('plan_tiers.codes', []);
        /** @var array<string, array<string, mixed>> $entitlementsByTier */
        $entitlementsByTier = (array) config('plan_tiers.entitlements', []);

        $byCode = [];
        foreach ($codesByTier as $tier => $codes) {
            $flags = $entitlementsByTier[$tier] ?? null;
            if (! is_array($flags)) {
                continue;
            }

            foreach ($codes as $code) {
                if (! is_string($code) || $code === '') {
                    continue;
                }
                $byCode[$code] = $flags;
            }
        }

        foreach ($byCode as $code => $flags) {
            $plan = Plan::query()->where('code', $code)->first();
            if ($plan === null) {
                continue;
            }

            $entitlements = (array) $plan->entitlements;
            $changed = false;

            foreach ($flags as $key => $value) {
                if ($key === 'limits' && is_array($value)) {
                    $limits = is_array($entitlements['limits'] ?? null) ? $entitlements['limits'] : [];
                    foreach ($value as $limitKey => $limitValue) {
                        if (! array_key_exists($limitKey, $limits)) {
                            $limits[$limitKey] = $limitValue;
                            $changed = true;
                        }
                    }
                    $entitlements['limits'] = $limits;

                    continue;
                }

                if (! array_key_exists($key, $entitlements)) {
                    $entitlements[$key] = $value;
                    $changed = true;
                }
            }

            if ($changed) {
                $plan->forceFill(['entitlements' => $entitlements])->save();
            }
        }
    }

    public function down(): void
    {
        // Leave catalog flags in place — rolling them back would hide Pro packaging
        // without restoring prior subscription snapshot behavior.
    }
};
