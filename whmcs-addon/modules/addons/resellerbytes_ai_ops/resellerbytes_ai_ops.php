<?php

use ResellerBytesAiOps\CredentialsFile;
use ResellerBytesAiOps\DiagnosticCollector;
use ResellerBytesAiOps\SignedApiClient;
use Throwable;

if (! defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__.'/lib/CredentialsFile.php';
require_once __DIR__.'/lib/DiagnosticCollector.php';
require_once __DIR__.'/lib/SignedApiClient.php';

function resellerbytes_ai_ops_config(): array
{
    return [
        'name' => 'ResellerBytes AI Operations',
        'description' => 'Collects and submits allowlisted, read-only WHMCS diagnostics to ResellerBytes AI Operations.',
        'version' => '1.0.0',
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
            $credentials = CredentialsFile::load(
                (string) ($vars['credentials_file'] ?? ''),
                defined('ROOTDIR') ? (string) ROOTDIR : '',
                (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''),
            );

            $diagnostics = (new DiagnosticCollector())->collect();
            $result = (new SignedApiClient())->submit($credentials, $diagnostics);

            $message = $result['duplicate']
                ? 'This diagnostic snapshot was already received. No duplicate record was created.'
                : 'Diagnostic snapshot submitted successfully. No WHMCS business data was changed.';
            $messageType = 'success';
        } catch (Throwable $exception) {
            // Never display paths, secrets, remote response bodies, or exception details.
            $message = 'Diagnostics were not submitted. Verify the credentials file path, file permissions, HTTPS connectivity, and API configuration.';
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
            '">',
            '<input type="hidden" name="token" value="',
            $token,
            '">',
            '<button type="submit" name="run_diagnostics" value="1" class="btn btn-primary">',
            'Run diagnostics once',
            '</button>',
            '</form>',
        ]);
    }

    $output .= '</div></div>';

    echo $output;
}
