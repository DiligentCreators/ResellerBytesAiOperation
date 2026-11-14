<?php

return [
    'anchor' => [
        'private_key' => env('AUDIT_ANCHOR_PRIVATE_KEY'),
        'public_key' => env('AUDIT_ANCHOR_PUBLIC_KEY'),
        'storage' => [
            'bucket' => env('AUDIT_ANCHOR_BUCKET'),
            'endpoint' => env('AUDIT_ANCHOR_ENDPOINT'),
            'region' => env('AUDIT_ANCHOR_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
            'aws_cli_binary' => env('AUDIT_ANCHOR_AWS_CLI_BINARY', 'aws'),
            'access_key' => env('AUDIT_ANCHOR_AWS_ACCESS_KEY_ID'),
            'secret_key' => env('AUDIT_ANCHOR_AWS_SECRET_ACCESS_KEY'),
        ],
    ],
];
