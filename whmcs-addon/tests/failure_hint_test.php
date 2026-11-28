<?php

use ResellerBytesAiOps\FailureHint;

require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/FailureHint.php';

function assertHint(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$codes = [
    'credentials_file_not_configured',
    'credentials_file_path_must_be_absolute',
    'credentials_file_root_paths_unavailable',
    'credentials_file_unavailable',
    'credentials_file_symlink_not_allowed',
    'credentials_file_must_be_outside_web_root',
    'credentials_file_permissions_invalid',
    'credentials_file_size_invalid',
    'credentials_file_unreadable',
    'credentials_file_invalid_json',
    'credentials_file_invalid_structure',
    'credentials_file_invalid_values',
    'curl_extension_unavailable',
    'request_encoding_failed',
    'http_client_initialization_failed',
    'http_client_configuration_failed',
    'http_dns_failed',
    'http_connect_failed',
    'http_timeout',
    'http_tls_failed',
    'http_transport_failed',
    'http_status_bad_request',
    'http_status_unauthenticated',
    'http_status_forbidden',
    'http_status_not_found',
    'http_status_conflict',
    'http_status_validation_failed',
    'http_status_rate_limited',
    'http_status_server_error',
    'http_status_unexpected',
    'http_response_too_large',
    'http_response_empty',
    'http_response_invalid',
    'diagnostic_response_invalid',
    'heartbeat_response_invalid',
    'pending_actions_response_invalid',
    'safe_action_result_response_invalid',
    'invalid_execution_id',
    'unexpected_error',
];

$generic = FailureHint::hint('totally_unknown_code_xyz');
assertHint($generic !== '', 'Generic hint must be non-empty.');
assertHint(
    str_contains($generic, 'credentials file') || str_contains($generic, 'HTTPS'),
    'Unknown codes must use the generic fallback hint.',
);

foreach ($codes as $code) {
    $hint = FailureHint::hint($code);
    assertHint($hint !== '', 'Hint missing for '.$code);
    assertHint($hint !== $generic, 'Hint for '.$code.' must not be the generic fallback.');
    assertHint(! preg_match('/(?<!permissions )\b\d{3}\b/', $hint), 'Hint for '.$code.' must not contain a digit-only HTTP status.');
    assertHint(
        ! preg_match('#(?:[A-Za-z]:\\\\|/var/|/home/|/etc/|/tmp/)#', $hint),
        'Hint for '.$code.' must not contain a filesystem path.',
    );
}

echo "WHMCS addon failure hint tests passed.\n";
