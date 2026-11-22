<?php

/**
 * Catalog tier → plan code mapping and default entitlement packaging.
 * Prices are never defined here — platform admins set amounts in the plan editor.
 *
 * "pro" maps to the top paid Agency/Enterprise codes already in the plans table
 * (business, enterprise). Add alternate codes here if the catalog grows.
 */
return [
    'codes' => [
        'starter' => ['starter'],
        'growth' => ['growth'],
        'pro' => ['business', 'enterprise', 'pro'],
    ],

    /*
     * Boolean entitlement flags and nested limits.history_days defaults per tier.
     * Backfills and PlanSeeder merge missing keys only — never overwrite admin edits.
     */
    'entitlements' => [
        'starter' => [
            'fixes.guided' => true,
            'fixes.one_click' => false,
            'fixes.bulk' => false,
            'fixes.auto' => false,
            'alerts.channels' => false,
            'reports.value' => false,
            'limits' => [
                'history_days' => 90,
            ],
        ],
        'growth' => [
            'fixes.guided' => true,
            'fixes.one_click' => true,
            'fixes.bulk' => true,
            'fixes.auto' => false,
            'alerts.channels' => true,
            'reports.value' => false,
            'limits' => [
                'history_days' => 365,
            ],
        ],
        'pro' => [
            'fixes.guided' => true,
            'fixes.one_click' => true,
            'fixes.bulk' => true,
            'fixes.auto' => true,
            'alerts.channels' => true,
            'reports.value' => true,
            'limits' => [
                'history_days' => 365,
            ],
        ],
    ],
];
