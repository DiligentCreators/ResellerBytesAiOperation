<?php

return [
    'anchor' => [
        'private_key' => env('AUDIT_ANCHOR_PRIVATE_KEY'),
        'public_key' => env('AUDIT_ANCHOR_PUBLIC_KEY'),
        'storage' => [
            'bucket' => env('AUDIT_ANCHOR_BUCKET'),
            'endpoint' => env('AUDIT_ANCHOR_ENDPOINT', env('AWS_ENDPOINT')),
            'region' => env('AUDIT_ANCHOR_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
            'aws_cli_binary' => env('AUDIT_ANCHOR_AWS_CLI_BINARY', 'aws'),
        ],
    ],
];
