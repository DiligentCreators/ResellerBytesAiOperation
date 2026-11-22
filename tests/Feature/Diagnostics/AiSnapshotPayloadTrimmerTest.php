<?php

use App\Services\Diagnostics\AiSnapshotPayloadTrimmer;

it('removes id lists and module queue items before ai analysis', function () {
    $payload = [
        'system' => ['whmcs_version' => '8.13.7'],
        'whmcs' => [
            'billing' => [
                'overdue_without_reminder' => [
                    'count' => 2,
                    'invoice_ids' => [1, 2, 3],
                ],
            ],
            'services' => [
                'orphan' => [
                    'count' => 1,
                    'service_ids' => [9],
                ],
            ],
            'module_queue' => [
                'pending_count' => 1,
                'items' => [
                    ['id' => 1, 'error' => 'fail'],
                ],
            ],
        ],
    ];

    $trimmed = app(AiSnapshotPayloadTrimmer::class)->trim($payload);

    expect($trimmed['whmcs']['billing']['overdue_without_reminder'])->toBe(['count' => 2])
        ->and($trimmed['whmcs']['services']['orphan'])->toBe(['count' => 1])
        ->and($trimmed['whmcs']['module_queue'])->toBe(['pending_count' => 1])
        ->and($trimmed)->not->toHaveKey('whmcs.module_queue.items');
});
