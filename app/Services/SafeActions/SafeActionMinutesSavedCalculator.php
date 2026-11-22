<?php

namespace App\Services\SafeActions;

use App\Services\Diagnostics\WhmcsIssueCatalog;
use App\Services\PlatformSettings;

class SafeActionMinutesSavedCalculator
{
    public function __construct(private readonly PlatformSettings $settings) {}

    public function calculate(?string $issueKey, int $itemsSucceeded): int
    {
        if ($itemsSucceeded < 1) {
            return 0;
        }

        $perItem = $this->minutesPerItem($issueKey);

        return max(0, $perItem * $itemsSucceeded);
    }

    private function minutesPerItem(?string $issueKey): int
    {
        if (is_string($issueKey) && $issueKey !== '') {
            $overrides = $this->overrides();
            if (isset($overrides[$issueKey]) && is_numeric($overrides[$issueKey])) {
                return max(0, (int) $overrides[$issueKey]);
            }

            return max(0, WhmcsIssueCatalog::getInt($issueKey, 'minutes_saved', 15));
        }

        return 15;
    }

    /**
     * @return array<string, mixed>
     */
    private function overrides(): array
    {
        try {
            $value = $this->settings->get('fixes.minutes_saved_overrides');
        } catch (\Throwable) {
            $value = config('fixes.minutes_saved_overrides', []);
        }

        return is_array($value) ? $value : [];
    }
}
