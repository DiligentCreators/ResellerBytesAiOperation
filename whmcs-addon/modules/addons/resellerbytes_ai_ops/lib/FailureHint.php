<?php

namespace ResellerBytesAiOps;

/**
 * Plain-English hints for sanitized failure codes shown in the admin UI.
 */
final class FailureHint
{
    private const GENERIC = 'Check the credentials file path and permissions, HTTPS connectivity to ResellerBytes AI Operations, and that the integration is active.';

    /** @var array<string, string> */
    private const HINTS = [
        'credentials_file_not_configured' => 'Set the absolute path to the credentials JSON file in the addon configuration.',
        'credentials_file_path_must_be_absolute' => 'The credentials file path must be an absolute filesystem path starting with a slash.',
        'credentials_file_root_paths_unavailable' => 'WHMCS could not resolve the installation root or document root needed to validate the credentials file location.',
        'credentials_file_unavailable' => 'The credentials file was not found or is not readable by the PHP runtime user.',
        'credentials_file_symlink_not_allowed' => 'The credentials file path must not be a symlink; point directly at the real file.',
        'credentials_file_must_be_outside_web_root' => 'Move the credentials file outside both the WHMCS installation root and the public web document root.',
        'credentials_file_permissions_invalid' => 'The credentials file must have permissions 0600.',
        'credentials_file_size_invalid' => 'The credentials file size is outside the allowed range; keep a small JSON file with only integration_id and secret.',
        'credentials_file_unreadable' => 'The credentials file could not be read by the PHP runtime user.',
        'credentials_file_invalid_json' => 'The credentials file is not valid JSON.',
        'credentials_file_invalid_structure' => 'The credentials file must be a JSON object with exactly integration_id and secret.',
        'credentials_file_invalid_values' => 'The credentials file values are invalid; integration_id must be a UUID and secret a hex string of the expected length.',
        'curl_extension_unavailable' => 'Enable the PHP cURL extension for the web (and CLI) PHP runtime.',
        'request_encoding_failed' => 'The diagnostic payload could not be encoded as JSON; check for unexpected binary data in collected signals.',
        'http_client_initialization_failed' => 'PHP cURL could not start an HTTPS client; verify the cURL extension is healthy.',
        'http_client_configuration_failed' => 'PHP cURL rejected the HTTPS client options; verify TLS support in the PHP runtime.',
        'http_dns_failed' => 'DNS lookup for ops.resellerbytes.com failed; check nameservers and outbound DNS from this server.',
        'http_connect_failed' => 'Could not open an HTTPS connection to ResellerBytes AI Operations; check firewall egress and network routing.',
        'http_timeout' => 'The HTTPS request timed out; check latency, firewall rules, and that ops.resellerbytes.com is reachable.',
        'http_tls_failed' => 'TLS negotiation or certificate verification failed; ensure the server CA bundle is current and HTTPS is not intercepted.',
        'http_transport_failed' => 'The HTTPS transport failed before a usable HTTP response was received; check cURL, TLS, and outbound HTTPS.',
        'http_status_bad_request' => 'ResellerBytes AI Operations rejected the request as malformed; update the addon and retry.',
        'http_status_unauthenticated' => 'ResellerBytes AI Operations rejected the request signature or integration ID. Check the integration is active and the credentials file holds its current ID and secret (rotate and rewrite the file if unsure).',
        'http_status_forbidden' => 'The workspace subscription is inactive, its diagnostics allowance is used up, or the workspace is suspended.',
        'http_status_not_found' => 'The requested API resource was not found; update the addon or confirm the Safe Action still exists.',
        'http_status_conflict' => 'The request conflicted with an existing record (for example a reused nonce or snapshot identifier).',
        'http_status_validation_failed' => 'ResellerBytes AI Operations rejected the payload shape; update the addon to the supported schema version.',
        'http_status_rate_limited' => 'Too many requests were sent; wait briefly and retry.',
        'http_status_server_error' => 'ResellerBytes AI Operations returned a server error; retry later or contact support if it persists.',
        'http_status_unexpected' => 'ResellerBytes AI Operations returned an unexpected HTTP status; retry later or update the addon.',
        'http_response_too_large' => 'The HTTPS response exceeded the allowed size; retry later or contact support if it persists.',
        'http_response_empty' => 'ResellerBytes AI Operations returned an empty success response; retry later.',
        'http_response_invalid' => 'ResellerBytes AI Operations returned a non-JSON success response; retry later or update the addon.',
        'diagnostic_response_invalid' => 'The diagnostic submission response was missing required fields; update the addon and retry.',
        'heartbeat_response_invalid' => 'The heartbeat response was missing required fields; update the addon and retry.',
        'pending_actions_response_invalid' => 'The pending Safe Actions response was missing required fields; update the addon and retry.',
        'safe_action_result_response_invalid' => 'The Safe Action result response was missing required fields; update the addon and retry.',
        'invalid_execution_id' => 'The Safe Action execution identifier is invalid; refresh pending actions and try again.',
        'unexpected_error' => 'An unexpected error occurred. Check the credentials file, HTTPS connectivity, and Activity Log for the sanitized reason code.',
    ];

    public static function hint(string $code): string
    {
        return self::HINTS[$code] ?? self::GENERIC;
    }
}
