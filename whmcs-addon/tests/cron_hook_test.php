<?php

namespace ResellerBytesAiOps {
    use stdClass;

    if (! function_exists(__NAMESPACE__.'\\curl_init')) {
        function curl_init(string $url): object
        {
            $GLOBALS['rb_mock_curl_url'] = $url;

            return new stdClass;
        }

        function curl_setopt_array(object $handle, array $options): bool
        {
            $GLOBALS['rb_mock_curl_options'] = $options;

            return true;
        }

        function curl_exec(object $handle): bool
        {
            if (($GLOBALS['rb_mock_curl_exec_result'] ?? true) !== true) {
                return false;
            }

            $responseBody = $GLOBALS['rb_mock_response_body'] ?? '{"data":{"accepted":true,"auto_collect_due":false,"pending_approved_actions":false}}';
            $callback = $GLOBALS['rb_mock_curl_options'][CURLOPT_WRITEFUNCTION];

            return $callback($handle, $responseBody) === strlen($responseBody);
        }

        function curl_getinfo(object $handle, int $option): int
        {
            return $GLOBALS['rb_mock_http_status'] ?? 202;
        }

        function curl_close(object $handle): void {}
    }
}

namespace WHMCS\Database {
    final class Capsule
    {
        public static function table(string $table): CapsuleQuery
        {
            return new CapsuleQuery($table);
        }
    }

    final class CapsuleQuery
    {
        /** @var array<string, string> */
        private array $wheres = [];

        public function __construct(private readonly string $table) {}

        public function where(string $column, string $value): self
        {
            $this->wheres[$column] = $value;

            return $this;
        }

        public function value(string $column): ?string
        {
            if ($this->table === 'tbladdonmodules'
                && ($this->wheres['module'] ?? '') === 'resellerbytes_ai_ops'
                && ($this->wheres['setting'] ?? '') === 'credentials_file') {
                $path = $GLOBALS['rb_test_credentials_path'] ?? '';

                return is_string($path) ? $path : '';
            }

            if ($this->table === 'tblconfiguration'
                && ($this->wheres['setting'] ?? '') === 'Version') {
                return '8.13.7';
            }

            return null;
        }
    }
}

namespace {
    function add_hook(string $name, int $priority, callable $callback): void
    {
        $GLOBALS['rb_test_hooks'][$name][] = $callback;
    }

    function logActivity(string $message): void
    {
        $GLOBALS['rb_activity_logs'][] = $message;
    }

    function assertCronHook(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            removeTree($path.DIRECTORY_SEPARATOR.$entry);
        }

        rmdir($path);
    }

    $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rb_cron_hook_'.bin2hex(random_bytes(8));
    $whmcsRoot = $base.DIRECTORY_SEPARATOR.'whmcs';
    $attachmentsTemp = $whmcsRoot.DIRECTORY_SEPARATOR.'attachments'.DIRECTORY_SEPARATOR.'temp';
    $credentialsPath = $base.DIRECTORY_SEPARATOR.'credentials.json';
    $secret = bin2hex(random_bytes(32));
    $integrationId = sprintf(
        '%08x-%04x-4%03x-%01x%03x-%012x',
        random_int(0, 0xFFFFFFFF),
        random_int(0, 0xFFFF),
        random_int(0, 0x0FFF),
        random_int(8, 0xB),
        random_int(0, 0x0FFF),
        random_int(0, 0xFFFFFFFFFFFF),
    );

    mkdir($attachmentsTemp, 0700, true);

    $credentialsJson = json_encode([
        'integration_id' => $integrationId,
        'secret' => $secret,
    ], JSON_THROW_ON_ERROR);
    file_put_contents($credentialsPath, $credentialsJson);
    chmod($credentialsPath, 0600);

    $GLOBALS['rb_test_hooks'] = [];
    $GLOBALS['rb_activity_logs'] = [];
    $GLOBALS['rb_mock_curl_url'] = null;
    $GLOBALS['rb_mock_curl_options'] = null;
    $GLOBALS['rb_mock_response_body'] = '{"data":{"accepted":true,"auto_collect_due":false,"pending_approved_actions":false}}';
    $GLOBALS['rb_mock_http_status'] = 202;
    $GLOBALS['rb_mock_curl_exec_result'] = true;
    $GLOBALS['rb_test_credentials_path'] = $credentialsPath;
    $GLOBALS['resellerbytes_ai_ops_last_hook_failure'] = null;

    if (! defined('WHMCS')) {
        define('WHMCS', true);
    }

    if (! defined('ROOTDIR')) {
        define('ROOTDIR', $whmcsRoot);
    }

    try {
        require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/hooks.php';

        assertCronHook(
            isset($GLOBALS['rb_test_hooks']['AfterCronJob'][0])
            && is_callable($GLOBALS['rb_test_hooks']['AfterCronJob'][0]),
            'AfterCronJob hook was not registered.',
        );

        $afterCronJob = $GLOBALS['rb_test_hooks']['AfterCronJob'][0];

        // Empty credentials_file setting must stay a silent no-op.
        $GLOBALS['rb_test_credentials_path'] = '';
        $GLOBALS['rb_activity_logs'] = [];
        $GLOBALS['rb_mock_curl_url'] = null;
        $_SERVER['DOCUMENT_ROOT'] = '';
        $afterCronJob();
        assertCronHook($GLOBALS['rb_mock_curl_url'] === null, 'Empty credentials_file must not send a heartbeat.');
        assertCronHook($GLOBALS['rb_activity_logs'] === [], 'Empty credentials_file must not write activity logs.');

        // Invalid credentials path: log failure code once, never the secret.
        $GLOBALS['rb_test_credentials_path'] = 'relative/credentials.json';
        $GLOBALS['rb_activity_logs'] = [];
        $GLOBALS['resellerbytes_ai_ops_last_hook_failure'] = null;
        $_SERVER['DOCUMENT_ROOT'] = '';
        $afterCronJob();
        assertCronHook(count($GLOBALS['rb_activity_logs']) === 1, 'Invalid credentials must log a single activity line.');
        assertCronHook(
            str_starts_with($GLOBALS['rb_activity_logs'][0], 'ResellerBytes AI Ops cron hook failed: '),
            'Failure log must use the cron hook failure prefix.',
        );
        assertCronHook(
            str_contains($GLOBALS['rb_activity_logs'][0], 'credentials_file_path_must_be_absolute'),
            'Failure log must include the fixed exception code.',
        );
        assertCronHook(
            ! str_contains($GLOBALS['rb_activity_logs'][0], $secret),
            'Failure log must never contain the credentials secret.',
        );
        assertCronHook(
            ! str_contains($GLOBALS['rb_activity_logs'][0], $integrationId),
            'Failure log must never contain the integration id.',
        );
        assertCronHook(
            ! str_contains($GLOBALS['rb_activity_logs'][0], $credentialsPath),
            'Failure log must never contain the credentials path.',
        );

        $GLOBALS['rb_activity_logs'] = [];
        $afterCronJob();
        assertCronHook($GLOBALS['rb_activity_logs'] === [], 'Identical hook failure codes must not spam the activity log.');

        // Success path under CLI with empty DOCUMENT_ROOT (POSIX absolute paths only).
        if (DIRECTORY_SEPARATOR === '/') {
            $GLOBALS['rb_test_credentials_path'] = $credentialsPath;
            $GLOBALS['rb_activity_logs'] = [];
            $GLOBALS['rb_mock_curl_url'] = null;
            $GLOBALS['rb_mock_curl_options'] = null;
            $GLOBALS['resellerbytes_ai_ops_last_hook_failure'] = null;
            $_SERVER['DOCUMENT_ROOT'] = '';
            $afterCronJob();

            assertCronHook(
                $GLOBALS['rb_mock_curl_url'] === 'https://ops.resellerbytes.com/api/v1/whmcs/heartbeats',
                'CLI AfterCronJob with empty DOCUMENT_ROOT must POST a heartbeat.',
            );
            assertCronHook(is_array($GLOBALS['rb_mock_curl_options']), 'Heartbeat curl options were not captured.');

            $headers = $GLOBALS['rb_mock_curl_options'][CURLOPT_HTTPHEADER] ?? [];
            assertCronHook(is_array($headers), 'Heartbeat request headers were missing.');

            $headerBlob = implode("\n", $headers);
            assertCronHook(str_contains($headerBlob, 'X-RB-Integration: '), 'Missing X-RB-Integration header.');
            assertCronHook(str_contains($headerBlob, 'X-RB-Timestamp: '), 'Missing X-RB-Timestamp header.');
            assertCronHook(str_contains($headerBlob, 'X-RB-Nonce: '), 'Missing X-RB-Nonce header.');
            assertCronHook(str_contains($headerBlob, 'X-RB-Signature: '), 'Missing X-RB-Signature header.');
            assertCronHook($GLOBALS['rb_activity_logs'] === [], 'Successful CLI heartbeat must not log a hook failure.');
        } else {
            echo "Skipping CLI heartbeat success assertion on non-POSIX (CredentialsFile requires POSIX absolute paths).\n";
        }
    } finally {
        removeTree($base);
    }

    echo "WHMCS addon cron hook tests passed.\n";
}
