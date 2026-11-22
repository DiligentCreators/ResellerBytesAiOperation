<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tenants\StoreTenantAlertChannelRequest;
use App\Http\Requests\Tenants\UpdateTenantAlertChannelRequest;
use App\Models\Tenant;
use App\Models\TenantAlertChannel;
use App\Models\TenantAlertDelivery;
use App\Models\User;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Tenancy\TenantEntitlementService;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TenantAlertChannelController extends Controller
{
    public function index(
        Request $request,
        Tenant $tenant,
        TenantRoleAuthorization $authorization,
        TenantEntitlementService $entitlements,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $membership = $authorization->require($user, $tenant->id, 'tenant.read');
        $canManage = $authorization->allows($membership, 'tenant.settings.manage');

        $channelsEntitled = $this->hasChannelsEntitlement($entitlements, $tenant->id);

        $channels = TenantAlertChannel::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TenantAlertChannel $channel): array => $this->serializeChannel($channel));

        $deliveries = TenantAlertDelivery::query()
            ->where('tenant_id', $tenant->id)
            ->with('channel:id,name,type')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(static fn (TenantAlertDelivery $delivery): array => [
                'id' => $delivery->id,
                'channel_id' => $delivery->channel_id,
                'channel_name' => $delivery->channel?->name,
                'channel_type' => $delivery->channel?->type,
                'alert_key' => $delivery->alert_key,
                'dedupe_key' => $delivery->dedupe_key,
                'status' => $delivery->status,
                'error' => $delivery->error,
                'sent_at' => $delivery->sent_at?->toIso8601String(),
                'created_at' => $delivery->created_at?->toIso8601String(),
            ]);

        return Inertia::render('tenants/alerts', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'channels' => $channels,
            'deliveries' => $deliveries,
            'canManage' => $canManage,
            'channelsEntitled' => $channelsEntitled,
            'eventKeys' => array_values(array_filter(
                (array) config('alerts.event_keys', []),
                static fn (mixed $key): bool => is_string($key) && $key !== 'alert.test',
            )),
            'channelTypes' => TenantAlertChannel::TYPES,
            'status' => $request->session()->get('status'),
            'createdSigningSecret' => $request->session()->get('createdSigningSecret'),
        ]);
    }

    public function store(
        StoreTenantAlertChannelRequest $request,
        Tenant $tenant,
        TenantRoleAuthorization $authorization,
        TenantEntitlementService $entitlements,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $authorization->require($user, $tenant->id, 'tenant.settings.manage');
        $entitlements->requireFeature($tenant->id, 'alerts.channels');

        $data = $request->validated();
        $signingSecret = null;

        if ($data['type'] === TenantAlertChannel::TYPE_WEBHOOK) {
            $signingSecret = Str::random(64);
        }

        $channel = TenantAlertChannel::query()->create([
            'tenant_id' => $tenant->id,
            'type' => $data['type'],
            'name' => $data['name'],
            'target' => $data['target'],
            'signing_secret' => $signingSecret,
            'events' => array_values($data['events']),
            'enabled' => $data['enabled'] ?? true,
        ]);

        $redirect = redirect()
            ->route('tenants.alerts.index', $tenant)
            ->with('status', 'Alert channel created.');

        if ($signingSecret !== null) {
            $redirect->with('createdSigningSecret', [
                'channel_id' => $channel->id,
                'secret' => $signingSecret,
            ]);
        }

        return $redirect;
    }

    public function update(
        UpdateTenantAlertChannelRequest $request,
        Tenant $tenant,
        TenantAlertChannel $channel,
        TenantRoleAuthorization $authorization,
        TenantEntitlementService $entitlements,
    ): RedirectResponse {
        $this->assertChannelBelongsToTenant($tenant, $channel);

        /** @var User $user */
        $user = $request->user();
        $authorization->require($user, $tenant->id, 'tenant.settings.manage');
        $entitlements->requireFeature($tenant->id, 'alerts.channels');

        $data = $request->validated();
        $updates = [
            'name' => $data['name'],
            'events' => array_values($data['events']),
            'enabled' => $data['enabled'] ?? $channel->enabled,
        ];

        if (array_key_exists('target', $data) && is_string($data['target']) && $data['target'] !== '') {
            $updates['target'] = $data['target'];
        }

        $channel->forceFill($updates)->save();

        return redirect()
            ->route('tenants.alerts.index', $tenant)
            ->with('status', 'Alert channel updated.');
    }

    public function destroy(
        Request $request,
        Tenant $tenant,
        TenantAlertChannel $channel,
        TenantRoleAuthorization $authorization,
        TenantEntitlementService $entitlements,
    ): RedirectResponse {
        $this->assertChannelBelongsToTenant($tenant, $channel);

        /** @var User $user */
        $user = $request->user();
        $authorization->require($user, $tenant->id, 'tenant.settings.manage');
        $entitlements->requireFeature($tenant->id, 'alerts.channels');

        $channel->delete();

        return redirect()
            ->route('tenants.alerts.index', $tenant)
            ->with('status', 'Alert channel deleted.');
    }

    public function test(
        Request $request,
        Tenant $tenant,
        TenantAlertChannel $channel,
        TenantRoleAuthorization $authorization,
        TenantEntitlementService $entitlements,
        AlertDispatcher $dispatcher,
    ): RedirectResponse {
        $this->assertChannelBelongsToTenant($tenant, $channel);

        /** @var User $user */
        $user = $request->user();
        $authorization->require($user, $tenant->id, 'tenant.settings.manage');
        $entitlements->requireFeature($tenant->id, 'alerts.channels');

        $ok = $dispatcher->sendTest($channel->fresh() ?? $channel);

        return redirect()
            ->route('tenants.alerts.index', $tenant)
            ->with('status', $ok ? 'Test alert sent.' : 'Test alert failed. Check the delivery log and channel last error.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeChannel(TenantAlertChannel $channel): array
    {
        $targetHint = match ($channel->type) {
            TenantAlertChannel::TYPE_EMAIL => $this->maskEmail((string) $channel->target),
            default => $this->maskUrl((string) $channel->target),
        };

        return [
            'id' => $channel->id,
            'type' => $channel->type,
            'name' => $channel->name,
            'target_hint' => $targetHint,
            'events' => is_array($channel->events) ? $channel->events : [],
            'enabled' => (bool) $channel->enabled,
            'verified_at' => $channel->verified_at?->toIso8601String(),
            'last_error' => $channel->last_error,
            'last_sent_at' => $channel->last_sent_at?->toIso8601String(),
            'has_signing_secret' => is_string($channel->signing_secret) && $channel->signing_secret !== '',
            'created_at' => $channel->created_at?->toIso8601String(),
        ];
    }

    private function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '••••';
        }

        [$local, $domain] = explode('@', $email, 2);
        $prefix = mb_substr($local, 0, 1);

        return $prefix.'•••@'.$domain;
    }

    private function maskUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? 'https://'.$host.'/•••' : 'https://•••';
    }

    private function assertChannelBelongsToTenant(Tenant $tenant, TenantAlertChannel $channel): void
    {
        if ($channel->tenant_id !== $tenant->id) {
            abort(404);
        }
    }

    private function hasChannelsEntitlement(TenantEntitlementService $entitlements, string $tenantId): bool
    {
        try {
            $entitlements->requireFeature($tenantId, 'alerts.channels');

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }
}
