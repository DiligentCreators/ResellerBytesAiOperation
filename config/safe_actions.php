<?php

/**
 * Code-defined Safe Action allowlist.
 * Only these keys may be requested, approved, or executed. The LLM cannot invent actions.
 */
return [
    'actions' => [
        'health_version_ping' => [
            'label' => 'Read-only health / version ping',
            'description' => 'Report WHMCS version, addon version, and last signed snapshot freshness from evidence already in this platform.',
            'risk_level' => 'low',
            'requires_password_confirm' => false,
            'requires_finding' => false,
            'auto_verify' => true,
            'entitlement' => 'safe_actions.enabled',
        ],
        'permissions_audit_report' => [
            'label' => 'Permissions audit report',
            'description' => 'Build a read-only permissions / exposure report from the latest diagnostic snapshot evidence.',
            'risk_level' => 'low',
            'requires_password_confirm' => false,
            'requires_finding' => false,
            'auto_verify' => true,
            'entitlement' => 'safe_actions.enabled',
        ],
        'whmcs_admin_deep_link' => [
            'label' => 'WHMCS admin deep link',
            'description' => 'Generate a pre-filled WHMCS admin path hint with finding context. No secrets are embedded in the link.',
            'risk_level' => 'low',
            'requires_password_confirm' => false,
            'requires_finding' => false,
            'auto_verify' => true,
            'entitlement' => 'safe_actions.enabled',
        ],
        'request_diagnostic_collect' => [
            'label' => 'Request diagnostic collect',
            'description' => 'Queue a human-approved request for the next read-only WHMCS addon diagnostic collect. This app does not push writes into WHMCS.',
            'risk_level' => 'medium',
            'requires_password_confirm' => true,
            'requires_finding' => false,
            'auto_verify' => false,
            'entitlement' => 'safe_actions.enabled',
        ],
    ],
];
