<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;

test('Redis can serve cache, session, and queue workloads', function () {
    config([
        'cache.default' => 'redis',
        'session.driver' => 'redis',
        'session.connection' => 'default',
        'queue.default' => 'redis',
    ]);

    $cacheKey = 'redis-infrastructure-test-'.bin2hex(random_bytes(8));

    Cache::store('redis')->put($cacheKey, 'available', 30);

    expect(Cache::store('redis')->get($cacheKey))->toBe('available');

    Cache::store('redis')->forget($cacheKey);

    $session = Session::driver('redis');
    $session->start();
    $session->put('redis_infrastructure_test', 'available');
    $session->save();

    expect($session->get('redis_infrastructure_test'))->toBe('available');

    expect(Queue::connection('redis')->size('default'))->toBeInt();
});
