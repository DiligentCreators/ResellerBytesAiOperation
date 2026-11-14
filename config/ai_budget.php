<?php

return [
    'daily_limit_micro_usd' => (int) env('AI_DAILY_BUDGET_MICRO_USD', 5_000_000),
    'monthly_limit_micro_usd' => (int) env('AI_MONTHLY_BUDGET_MICRO_USD', 50_000_000),
    'max_input_bytes' => (int) env('AI_MAX_INPUT_BYTES', 65_536),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 3_000),
    'price_cards' => [
        'gpt-5.6-sol' => [
            'version' => '2026-11-12.gpt-5.6-sol.short-context.v1',
            'input_tenths_micro_usd_per_token' => 40,
            'output_tenths_micro_usd_per_token' => 200,
            'cached_input_tenths_micro_usd_per_token' => 4,
            'valid_until' => '2026-11-30T23:59:59Z',
        ],
    ],
];
