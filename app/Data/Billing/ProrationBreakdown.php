<?php

namespace App\Data\Billing;

final readonly class ProrationBreakdown
{
    public function __construct(
        public int $newPriceMinor,
        public int $creditMinor,
        public int $totalDueMinor,
        public int $remainingDays,
        public int $totalDays,
        public string $currency,
    ) {}

    /**
     * @return array{
     *     new_price_minor: int,
     *     credit_minor: int,
     *     total_due_minor: int,
     *     remaining_days: int,
     *     total_days: int,
     *     currency: string
     * }
     */
    public function toArray(): array
    {
        return [
            'new_price_minor' => $this->newPriceMinor,
            'credit_minor' => $this->creditMinor,
            'total_due_minor' => $this->totalDueMinor,
            'remaining_days' => $this->remainingDays,
            'total_days' => $this->totalDays,
            'currency' => $this->currency,
        ];
    }
}
