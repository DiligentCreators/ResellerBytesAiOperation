<?php

/**
 * Code-defined Safe Action allowlist.
 * Only these keys may be requested, approved, or executed. The LLM cannot invent actions.
 *
 * execution_target:
 * - platform: runs inside Laravel after approval
 * - addon: queues for the WHMCS addon pull/apply runner
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
            'execution_target' => 'platform',
            'entitlement' => 'safe_actions.enabled',
        ],
        'permissions_audit_report' => [
            'label' => 'Permissions audit report',
            'description' => 'Build a read-only permissions / exposure report from the latest diagnostic snapshot evidence.',
            'risk_level' => 'low',
            'requires_password_confirm' => false,
            'requires_finding' => false,
            'auto_verify' => true,
            'execution_target' => 'platform',
            'entitlement' => 'safe_actions.enabled',
        ],
        'whmcs_admin_deep_link' => [
            'label' => 'WHMCS admin deep link',
            'description' => 'Generate a pre-filled WHMCS admin path hint with finding context. No secrets are embedded in the link.',
            'risk_level' => 'low',
            'requires_password_confirm' => false,
            'requires_finding' => false,
            'auto_verify' => true,
            'execution_target' => 'platform',
            'entitlement' => 'safe_actions.enabled',
        ],
        'pull_and_run_diagnostic_collect' => [
            'label' => 'Pull and run diagnostic collect',
            'description' => 'Queue an approved request for the WHMCS addon to run a read-only diagnostic collect and submit a signed snapshot.',
            'risk_level' => 'medium',
            'requires_password_confirm' => true,
            'requires_finding' => false,
            'auto_verify' => false,
            'execution_target' => 'addon',
            'entitlement' => 'safe_actions.enabled',
        ],
        'request_diagnostic_collect' => [
            'label' => 'Request diagnostic collect',
            'description' => 'Legacy alias: queue a human-approved WHMCS addon diagnostic collect (same as pull and run).',
            'risk_level' => 'medium',
            'requires_password_confirm' => true,
            'requires_finding' => false,
            'auto_verify' => false,
            'execution_target' => 'addon',
            'entitlement' => 'safe_actions.enabled',
        ],
        'disable_php_display_errors' => [
            'label' => 'Disable PHP display_errors',
            'description' => 'Allowlisted remediation: write a scoped .user.ini override under the WHMCS root so display_errors is Off. Uses local PHP permissions first, then optional host credentials.',
            'risk_level' => 'medium',
            'requires_password_confirm' => true,
            'requires_finding' => false,
            'auto_verify' => false,
            'execution_target' => 'addon',
            'entitlement' => 'safe_actions.enabled',
        ],
        'harden_configuration_php_mode' => [
            'label' => 'Harden configuration.php permissions',
            'description' => 'Allowlisted remediation: remove write permission from configuration.php for the web user (chmod). Uses local PHP permissions first, then optional host credentials. Does not edit file contents.',
            'risk_level' => 'medium',
            'requires_password_confirm' => true,
            'requires_finding' => false,
            'auto_verify' => false,
            'execution_target' => 'addon',
            'entitlement' => 'safe_actions.enabled',
        ],
    ],
];
