<?php

namespace WHMCS\Config {
    final class Setting
    {
        public static function getValue(string $setting): ?string
        {
            return match ($setting) {
                'Version' => '8.13.7-release.1',
                'SystemURL' => 'https://portal.example.test',
                default => null,
            };
        }
    }
}

namespace WHMCS\Database {
    use RuntimeException;

    final class Capsule
    {
        public static function connection(): object
        {
            return new class
            {
                public function select(string $query): array
                {
                    if ($query !== 'SELECT VERSION() AS version') {
                        throw new RuntimeException('Unexpected database query.');
                    }

                    return [(object) ['version' => '10.11.19-MariaDB']];
                }
            };
        }
    }
}

namespace {
    use ResellerBytesAiOps\DiagnosticCollector;
    use RuntimeException;

    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/DiagnosticCollector.php';

    function assertCollectorCondition(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'resellerbytes-ai-collector-'.bin2hex(random_bytes(8));
    mkdir($root, 0700, true);
    define('ROOTDIR', $root);

    file_put_contents($root.DIRECTORY_SEPARATOR.'configuration.php', '<?php');
    $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.62';
    ini_set('display_errors', '0');

    try {
        $payload = (new DiagnosticCollector)->collect();

        assertCollectorCondition($payload['system']['whmcs_version'] === '8.13.7-release.1', 'WHMCS version was not collected.');
        assertCollectorCondition($payload['system']['database_version'] === '10.11.19-MariaDB', 'Database version was not collected using the read-only version query.');
        assertCollectorCondition($payload['system']['application_url_configured'] === true, 'System URL status was not collected.');
        assertCollectorCondition($payload['system']['web_server'] === 'Apache', 'Web server value was not sanitized.');
        assertCollectorCondition($payload['system']['error_display_disabled'] === true, 'Error display state was not collected.');
        assertCollectorCondition(isset($payload['php']['extensions']['curl']), 'Allowlisted PHP extensions were not collected.');
        assertCollectorCondition($payload['system']['configuration_file_writable'] === true, 'Configuration file writability was not observed.');
        assertCollectorCondition(isset($payload['warnings'][0]['code']), 'Configuration file writability warning was not collected.');
        assertCollectorCondition(! array_key_exists('cron_php_version', $payload['system']), 'Unobservable cron PHP version was reported.');
        assertCollectorCondition(! array_key_exists('modules', $payload), 'Module status was reported without a verified collector.');

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        assertCollectorCondition(strpos($encoded, 'portal.example.test') === false, 'The configured system URL was transmitted.');
        assertCollectorCondition(strpos($encoded, 'customer') === false, 'Unexpected customer-related data was transmitted.');
    } finally {
        $configurationFile = $root.DIRECTORY_SEPARATOR.'configuration.php';

        if (is_file($configurationFile)) {
            unlink($configurationFile);
        }

        if (is_dir($root)) {
            rmdir($root);
        }
    }

    echo "WHMCS addon diagnostic collector tests passed.\n";
}
