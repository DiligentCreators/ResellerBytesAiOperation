<?php

use App\Models\TenantAlertChannel;
use App\Services\Alerts\AlertMessage;
use App\Services\Alerts\Drivers\WebhookAlertDriver;
use App\Support\Alerts\WebhookUrlValidator;
use App\Support\Http\HostDnsResolver;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakeHostDnsResolver;

it('requires https webhook urls', function () {
    $resolver = new FakeHostDnsResolver([
        'hooks.example.test' => ['203.0.113.10'],
    ]);
    $this->app->instance(HostDnsResolver::class, $resolver);
    $validator = app(WebhookUrlValidator::class);

    expect($validator->isValid('http://hooks.example.test/alerts', allowPrivate: false))->toBeFalse()
        ->and($validator->isValid('https://hooks.example.test/alerts', allowPrivate: false))->toBeTrue();
});

dataset('blocked_webhook_targets', [
    'ipv4 loopback' => ['https://127.0.0.1/hook', false],
    'ipv4 loopback 127.0.0.2' => ['https://127.0.0.2/hook', false],
    'bracketed ipv6 loopback' => ['https://[::1]/x', false],
    'unbracketed ipv6 loopback host' => ['https://[0:0:0:0:0:0:0:1]/hook', false],
    'unspecified ipv4' => ['https://0.0.0.0/hook', false],
    'unspecified ipv4 0.1.2.3' => ['https://0.1.2.3/hook', false],
    'unspecified ipv6 bracketed' => ['https://[::]/hook', false],
    'private 10' => ['https://10.0.0.5/hook', false],
    'private 172.16' => ['https://172.16.0.1/hook', false],
    'private 172.31' => ['https://172.31.255.255/hook', false],
    'private 192.168' => ['https://192.168.1.10/hook', false],
    'cgnat 100.64' => ['https://100.64.0.1/hook', false],
    'cgnat 100.127' => ['https://100.127.255.254/hook', false],
    'link local ipv4' => ['https://169.254.169.254/latest/meta-data', false],
    'link local ipv6' => ['https://[fe80::1]/hook', false],
    'ula fc00' => ['https://[fc00::1]/hook', false],
    'ula fd12' => ['https://[fd12:3456:789a::1]/hook', false],
    'ipv4-mapped loopback dotted' => ['https://[::ffff:127.0.0.1]/hook', false],
    'ipv4-mapped loopback hex' => ['https://[::ffff:7f00:1]/hook', false],
    'ipv4-mapped private 10' => ['https://[::ffff:10.0.0.1]/hook', false],
    'ipv4-compatible loopback' => ['https://[::127.0.0.1]/hook', false],
    'nat64 loopback' => ['https://[64:ff9b::127.0.0.1]/hook', false],
    '6to4 embedding 10.0.0.1' => ['https://[2002:0a00:0001::1]/hook', false],
    'multicast ipv4' => ['https://224.0.0.1/hook', false],
    'multicast ipv6' => ['https://[ff02::1]/hook', false],
    'reserved 240' => ['https://240.0.0.1/hook', false],
    'broadcast' => ['https://255.255.255.255/hook', false],
    'localhost' => ['https://localhost/hook', false],
    'star localhost' => ['https://hooks.localhost/hook', false],
    'star local' => ['https://printer.local/hook', false],
    'star internal' => ['https://api.internal/hook', false],
    'metadata hostname' => ['https://metadata.google.internal/hook', false],
    'metadata short' => ['https://metadata/hook', false],
    'decimal loopback' => ['https://2130706433/hook', false],
    'hex loopback' => ['https://0x7f000001/hook', false],
    'octal dotted loopback' => ['https://0177.0.0.1/hook', false],
    'short loopback 127.1' => ['https://127.1/hook', false],
    'octal integer loopback' => ['https://017700000001/hook', false],
    'trailing dot localhost' => ['https://localhost./hook', false],
    'public ipv4 passes' => ['https://8.8.8.8/hook', true],
    'public ipv4 mapped passes' => ['https://[::ffff:8.8.8.8]/hook', true],
]);

it('blocks private reserved and special-use webhook targets', function (string $url, bool $expectedValid) {
    $this->app->instance(HostDnsResolver::class, new FakeHostDnsResolver);
    $validator = app(WebhookUrlValidator::class);

    expect($validator->isValid($url, allowPrivate: false))->toBe($expectedValid);

    if (! $expectedValid) {
        expect($validator->validate($url, allowPrivate: false)['message'])->toContain('private or local');
    }
})->with('blocked_webhook_targets');

it('rejects hostnames that resolve to a private address at validation time', function () {
    $resolver = new FakeHostDnsResolver([
        'evil.example.test' => ['10.0.0.8'],
    ]);
    $this->app->instance(HostDnsResolver::class, $resolver);
    $validator = app(WebhookUrlValidator::class);

    expect($validator->isValid('https://evil.example.test/hook', allowPrivate: false))->toBeFalse();
});

it('allows unresolvable hostnames at creation but refuses them at send time', function () {
    $resolver = new FakeHostDnsResolver;
    $this->app->instance(HostDnsResolver::class, $resolver);
    $validator = app(WebhookUrlValidator::class);

    expect($validator->isValid('https://pending-hooks.example.test/inbound', allowPrivate: false))->toBeTrue();

    $prepared = $validator->prepareOutbound('https://pending-hooks.example.test/inbound', allowPrivate: false);
    expect($prepared['valid'])->toBeFalse()
        ->and($prepared['message'])->toContain('private or local');
});

it('rejects ip-like literals that do not parse even when dns would be empty', function () {
    $this->app->instance(HostDnsResolver::class, new FakeHostDnsResolver);
    $validator = app(WebhookUrlValidator::class);

    expect($validator->isValid('https://999.999.999.999/hook', allowPrivate: false))->toBeFalse();
});

it('pins dns with CURLOPT_RESOLVE and refuses redirects at send time', function () {
    $resolver = new FakeHostDnsResolver([
        'hooks.example.test' => ['203.0.113.10'],
    ]);
    $this->app->instance(HostDnsResolver::class, $resolver);

    $validator = app(WebhookUrlValidator::class);
    $prepared = $validator->prepareOutbound('https://hooks.example.test:8443/inbound', allowPrivate: false);

    expect($prepared['valid'])->toBeTrue()
        ->and($prepared['request_options']['allow_redirects'])->toBeFalse()
        ->and($prepared['request_options']['curl'][CURLOPT_RESOLVE])->toBe([
            'hooks.example.test:8443:203.0.113.10',
        ]);

    Http::preventStrayRequests();
    Http::fake([
        'hooks.example.test/*' => Http::response('', 302, [
            'Location' => 'https://127.0.0.1/steal',
        ]),
    ]);

    $channel = new TenantAlertChannel([
        'type' => TenantAlertChannel::TYPE_WEBHOOK,
        'target' => 'https://hooks.example.test/inbound/'.Str::random(8),
        'signing_secret' => Str::random(64),
    ]);

    $driver = app(WebhookAlertDriver::class);

    expect(fn () => $driver->send(
        $channel,
        'alert.test',
        new AlertMessage(title: 'Test', body: 'Body'),
    ))->toThrow(RuntimeException::class, 'alert_webhook_redirect_refused');

    Http::assertSentCount(1);
    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '127.0.0.1'));
});

it('refuses delivery when any resolved address is blocked', function () {
    $resolver = new FakeHostDnsResolver([
        'dual.example.test' => ['203.0.113.10', '127.0.0.1'],
    ]);
    $this->app->instance(HostDnsResolver::class, $resolver);

    $prepared = app(WebhookUrlValidator::class)
        ->prepareOutbound('https://dual.example.test/hook', allowPrivate: false);

    expect($prepared['valid'])->toBeFalse();
});
