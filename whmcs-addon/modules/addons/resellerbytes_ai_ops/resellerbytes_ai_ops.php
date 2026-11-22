<?php

use ResellerBytesAiOps\CredentialsFile;
use ResellerBytesAiOps\DiagnosticCollector;
use ResellerBytesAiOps\RemediationRunner;
use ResellerBytesAiOps\RuntimeRoots;
use ResellerBytesAiOps\SignedApiClient;

if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

require_once __DIR__.'/lib/CredentialsFile.php';
require_once __DIR__.'/lib/RuntimeRoots.php';
require_once __DIR__.'/lib/WhmcsDataSource.php';
require_once __DIR__.'/lib/CapsuleWhmcsDataSource.php';
require_once __DIR__.'/lib/SnapshotRedactor.php';
require_once __DIR__.'/lib/DiagnosticCollector.php';
require_once __DIR__.'/lib/SignedApiClient.php';
require_once __DIR__.'/lib/LocalApiClient.php';
require_once __DIR__.'/lib/WhmcsLocalApiClient.php';
require_once __DIR__.'/lib/ActivityLogger.php';
require_once __DIR__.'/lib/WhmcsActivityLogger.php';
require_once __DIR__.'/lib/FakeLocalApiClient.php';
require_once __DIR__.'/lib/RemediationRunner.php';

function resellerbytes_ai_ops_config(): array
{
    return [
        'name' => 'ResellerBytes AI Operations',
        'description' => 'Collects read-only WHMCS diagnostics and applies human-approved allowlisted Safe Actions for ResellerBytes AI Operations.',
        'version' => SignedApiClient::ADDON_VERSION,
        'author' => 'Diligent Creators Pvt. Ltd.',
        'language' => 'english',
        'fields' => [
            'credentials_file' => [
                'FriendlyName' => 'Credentials JSON File',
                'Type' => 'text',
                'Size' => '80',
                'Default' => '',
                'Description' => 'Absolute path to a JSON credentials file outside the WHMCS web root. The file must be readable by the PHP runtime user and have permissions 0600.',
            ],
        ],
    ];
}

function resellerbytes_ai_ops_activate(): array
{
    return [
        'status' => 'success',
        'description' => 'The addon is activated. No custom WHMCS tables are created and no business records are modified.',
    ];
}

function resellerbytes_ai_ops_deactivate(): array
{
    return [
        'status' => 'success',
        'description' => 'The addon is deactivated. The external credentials file is not modified or deleted.',
    ];
}

function resellerbytes_ai_ops_output(array $vars): void
{
    if (empty($_SESSION['adminid'])) {
        echo '<div class="alert alert-danger">An authenticated WHMCS administrator session is required.</div>';

        return;
    }

    $moduleLink = isset($vars['modulelink']) && is_string($vars['modulelink'])
        ? $vars['modulelink']
        : 'addonmodules.php?module=resellerbytes_ai_ops';

    $message = '';
    $messageType = 'info';

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['run_diagnostics'])) {
        try {
            $whmcsRoot = defined('ROOTDIR') ? (string) ROOTDIR : '';
            $credentials = CredentialsFile::load(
                (string) ($vars['credentials_file'] ?? ''),
                $whmcsRoot,
                RuntimeRoots::documentRootFor(PHP_SAPI, $_SERVER, $whmcsRoot),
            );

            $diagnostics = (new DiagnosticCollector)->collect();
            $result = (new SignedApiClient)->submit($credentials, $diagnostics);

            $message = $result['duplicate']
                ? 'This diagnostic snapshot was already received. No duplicate record was created.'
                : 'Diagnostic snapshot submitted successfully. No WHMCS business data was changed.';
            $messageType = 'success';
        } catch (Throwable $exception) {
            $message = 'Diagnostics were not submitted. Verify the credentials file path, file permissions, HTTPS connectivity, and API configuration.';
            $messageType = 'danger';
        }
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['apply_safe_actions'])) {
        try {
            $whmcsRoot = defined('ROOTDIR') ? (string) ROOTDIR : '';
            $credentials = CredentialsFile::load(
                (string) ($vars['credentials_file'] ?? ''),
                $whmcsRoot,
                RuntimeRoots::documentRootFor(PHP_SAPI, $_SERVER, $whmcsRoot),
            );

            $client = new SignedApiClient;
            $pending = $client->listPendingSafeActions($credentials);
            $runner = new RemediationRunner;
            $applied = 0;
            $failed = 0;

            foreach ($pending['actions'] as $action) {
                if (! is_array($action) || ! isset($action['id']) || ! is_string($action['id'])) {
                    continue;
                }

                $result = $runner->apply($credentials, $action, $client);
                $payload = [
                    'status' => $result['status'],
                    'summary' => $result['summary'],
                    'evidence' => $result['evidence'],
                ];
                if (isset($result['error_code'])) {
                    $payload['error_code'] = $result['error_code'];
                }

                $client->reportSafeActionResult($credentials, $action['id'], $payload);

                if ($result['status'] === 'success') {
                    $applied++;
                    if (! in_array($action['action_key'] ?? '', [
                        'pull_and_run_diagnostic_collect',
                        'request_diagnostic_collect',
                    ], true)) {
                        // Fresh evidence after mutating remediations.
                        $diagnostics = (new DiagnosticCollector)->collect();
                        $client->submit($credentials, $diagnostics);
                    }
                } else {
                    $failed++;
                }
            }

            if ($applied === 0 && $failed === 0) {
                $message = 'No approved Safe Actions are waiting for this installation.';
                $messageType = 'info';
            } else {
                $message = 'Applied '.$applied.' Safe Action(s). Failed '.$failed.'.';
                $messageType = $failed > 0 ? 'warning' : 'success';
            }
        } catch (Throwable $exception) {
            $message = 'Approved Safe Actions could not be applied. Verify credentials, HTTPS connectivity, and that this addon version supports Safe Actions.';
            $messageType = 'danger';
        }
    }

    $escapedModuleLink = htmlspecialchars($moduleLink, ENT_QUOTES, 'UTF-8');
    $output = implode('', [
        '<div class="panel panel-default">',
        '<div class="panel-heading"><strong>Read-only diagnostics</strong></div>',
        '<div class="panel-body">',
        '<p>This action collects an allowlisted set of technical signals and sends one signed HTTPS request to ResellerBytes AI Operations.</p>',
        '<p>It does not modify WHMCS customers, invoices, payments, hosting services, domains, or existing provisioning/registrar modules.</p>',
    ]);

    if ($message !== '') {
        $output .= implode('', [
            '<div class="alert alert-',
            htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'),
            '">',
            htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
            '</div>',
        ]);
    }

    if (! function_exists('generate_token')) {
        $output .= '<div class="alert alert-warning">The WHMCS admin CSRF token helper is unavailable. Manual submission is disabled.</div>';
    } else {
        $token = htmlspecialchars((string) generate_token('plain'), ENT_QUOTES, 'UTF-8');

        $output .= implode('', [
            '<form method="post" action="',
            $escapedModuleLink,
            '" style="display:inline-block;margin-right:8px;">',
            '<input type="hidden" name="token" value="',
            $token,
            '">',
            '<button type="submit" name="run_diagnostics" value="1" class="btn btn-primary">',
            'Run diagnostics once',
            '</button>',
            '</form>',
        ]);

        $tokenApply = htmlspecialchars((string) generate_token('plain'), ENT_QUOTES, 'UTF-8');
        $output .= implode('', [
            '<form method="post" action="',
            $escapedModuleLink,
            '" style="display:inline-block;">',
            '<input type="hidden" name="token" value="',
            $tokenApply,
            '">',
            '<button type="submit" name="apply_safe_actions" value="1" class="btn btn-success">',
            'Apply approved Safe Actions',
            '</button>',
            '</form>',
            '<p class="help-block" style="margin-top:12px;">Safe Actions must be requested and approved in ResellerBytes AI Operations first. This button only applies allowlisted, human-approved remediations for this installation.</p>',
        ]);
    }

    $output .= '</div></div>';

    echo $output;
}
