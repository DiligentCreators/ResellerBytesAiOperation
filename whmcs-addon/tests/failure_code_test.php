<?php

use ResellerBytesAiOps\FailureCode;

require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/FailureCode.php';

function assertFailureCodeSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message.' Expected '.var_export($expected, true).', got '.var_export($actual, true).'.');
    }
}

assertFailureCodeSame(
    'credentials_file_path_must_be_absolute',
    FailureCode::sanitize('credentials_file_path_must_be_absolute'),
    'Snake_case codes must pass through unchanged.',
);

assertFailureCodeSame(
    'heartbeat_response_invalid',
    FailureCode::sanitize('heartbeat_response_invalid'),
    'Short snake_case codes must pass through unchanged.',
);

assertFailureCodeSame(
    FailureCode::UNEXPECTED,
    FailureCode::sanitize('SQLSTATE[HY000] General error: /var/www/secret/path'),
    'SQL and path text must become unexpected_error.',
);

assertFailureCodeSame(
    FailureCode::UNEXPECTED,
    FailureCode::sanitize('Type error in /home/user/app.php on line 42'),
    'Uppercase and path text must become unexpected_error.',
);

assertFailureCodeSame(
    FailureCode::UNEXPECTED,
    FailureCode::sanitize('has spaces'),
    'Spaces must become unexpected_error.',
);

assertFailureCodeSame(
    FailureCode::UNEXPECTED,
    FailureCode::sanitize('HasUppercase'),
    'Uppercase letters must become unexpected_error.',
);

assertFailureCodeSame(
    FailureCode::UNEXPECTED,
    FailureCode::sanitize(str_repeat('a_', 40)),
    'Codes longer than 64 characters must become unexpected_error.',
);

assertFailureCodeSame(
    FailureCode::UNEXPECTED,
    FailureCode::sanitize(''),
    'Empty messages must become unexpected_error.',
);

echo "WHMCS addon failure code tests passed.\n";
