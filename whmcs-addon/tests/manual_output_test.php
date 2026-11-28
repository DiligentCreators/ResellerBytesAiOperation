<?php

namespace {
    function generate_token(string $type = 'plain'): string
    {
        return 'test-csrf-token';
    }

    function logActivity(string $message): void
    {
        $GLOBALS['rb_activity_logs'][] = $message;
    }

    function assertManualOutput(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    if (! defined('WHMCS')) {
        define('WHMCS', true);
    }

    if (! defined('ROOTDIR')) {
        define('ROOTDIR', sys_get_temp_dir().DIRECTORY_SEPARATOR.'rb_manual_output_root');
    }

    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/resellerbytes_ai_ops.php';

    $_SESSION['adminid'] = 1;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['DOCUMENT_ROOT'] = '';
    $GLOBALS['rb_activity_logs'] = [];
    unset($GLOBALS['resellerbytes_ai_ops_test_force_exception']);

    $_POST = ['run_diagnostics' => '1', 'token' => 'test-csrf-token'];
    ob_start();
    resellerbytes_ai_ops_output(['credentials_file' => '', 'modulelink' => 'addonmodules.php?module=resellerbytes_ai_ops']);
    $diagnosticsOutput = ob_get_clean();

    assertManualOutput(
        str_contains($diagnosticsOutput, 'Reason code: credentials_file_not_configured.'),
        'Manual diagnostics alert must include the sanitized reason code.',
    );
    assertManualOutput(
        str_contains($diagnosticsOutput, 'alert-danger'),
        'Manual diagnostics failure must use the danger alert.',
    );
    assertManualOutput(count($GLOBALS['rb_activity_logs']) === 1, 'Manual diagnostics must write exactly one activity log line.');
    assertManualOutput(
        $GLOBALS['rb_activity_logs'][0] === 'ResellerBytes AI Ops manual diagnostics failed: credentials_file_not_configured',
        'Manual diagnostics activity log line is incorrect.',
    );

    $GLOBALS['rb_activity_logs'] = [];
    $_POST = ['apply_safe_actions' => '1', 'token' => 'test-csrf-token'];
    ob_start();
    resellerbytes_ai_ops_output(['credentials_file' => '', 'modulelink' => 'addonmodules.php?module=resellerbytes_ai_ops']);
    $safeActionsOutput = ob_get_clean();

    assertManualOutput(
        str_contains($safeActionsOutput, 'Reason code: credentials_file_not_configured.'),
        'Safe Actions alert must include the sanitized reason code.',
    );
    assertManualOutput(count($GLOBALS['rb_activity_logs']) === 1, 'Safe Actions apply must write exactly one activity log line.');
    assertManualOutput(
        $GLOBALS['rb_activity_logs'][0] === 'ResellerBytes AI Ops Safe Actions apply failed: credentials_file_not_configured',
        'Safe Actions activity log line is incorrect.',
    );

    $dirtyPath = '/var/www/secret/credentials.json';
    $GLOBALS['rb_activity_logs'] = [];
    $GLOBALS['resellerbytes_ai_ops_test_force_exception'] = 'Unable to read '.$dirtyPath;
    $_POST = ['run_diagnostics' => '1', 'token' => 'test-csrf-token'];
    ob_start();
    resellerbytes_ai_ops_output(['credentials_file' => '', 'modulelink' => 'addonmodules.php?module=resellerbytes_ai_ops']);
    $dirtyOutput = ob_get_clean();
    unset($GLOBALS['resellerbytes_ai_ops_test_force_exception']);

    assertManualOutput(
        str_contains($dirtyOutput, 'Reason code: unexpected_error.'),
        'Path-bearing throwables must surface as unexpected_error.',
    );
    assertManualOutput(! str_contains($dirtyOutput, $dirtyPath), 'Alert must never echo a filesystem path.');
    assertManualOutput(! str_contains($dirtyOutput, 'Unable to read'), 'Alert must never echo the raw exception message.');
    assertManualOutput(count($GLOBALS['rb_activity_logs']) === 1, 'Dirty throwables must log exactly one activity line.');
    assertManualOutput(
        $GLOBALS['rb_activity_logs'][0] === 'ResellerBytes AI Ops manual diagnostics failed: unexpected_error',
        'Dirty throwables must log unexpected_error only.',
    );
    assertManualOutput(! str_contains($GLOBALS['rb_activity_logs'][0], $dirtyPath), 'Activity log must never contain a filesystem path.');

    echo "WHMCS addon manual output tests passed.\n";
}
