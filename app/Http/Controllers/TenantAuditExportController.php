<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Audit\AuditEventWriter;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\Tenancy\TenantRoleAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantAuditExportController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant, TenantRoleAuthorization $authorization, AuditEventWriter $audit): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $authorization->require($user, $tenant->id, 'audit.export');

        $filters = $request->validate([
            'from' => ['nullable', 'date', 'before_or_equal:to'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = isset($filters['from']) ? CarbonImmutable::parse($filters['from'])->utc() : null;
        $to = isset($filters['to']) ? CarbonImmutable::parse($filters['to'])->utc() : null;

        $audit->append([
            'actor_type' => 'user',
            'actor_id' => (string) $user->getKey(),
            'tenant_id' => $tenant->id,
            'event_type' => 'tenant.audit_export.requested',
            'subject_type' => 'tenant',
            'subject_id' => $tenant->id,
            'metadata' => ['from' => $from?->toIso8601String(), 'to' => $to?->toIso8601String(), 'format' => 'csv'],
            'ip_address' => $request->ip(),
            'occurred_at' => now(),
        ]);

        $filename = 'tenant-audit-'.$tenant->slug.'-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($tenant, $from, $to, $user): void {
            app(TenantDatabaseContext::class)->run(null, (int) $user->getKey(), function () use ($tenant, $from, $to, $user): void {
                $activeMembership = TenantMembership::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('user_id', $user->getKey())
                    ->where('status', TenantMembership::STATUS_ACTIVE)
                    ->exists();

                if (! $activeMembership) {
                    abort(403);
                }

                app(TenantDatabaseContext::class)->run($tenant->id, (int) $user->getKey(), function () use ($tenant, $from, $to): void {
                    $output = fopen('php://output', 'wb');
                    if ($output === false) {
                        return;
                    }

                    fputcsv($output, ['chain_sequence', 'occurred_at', 'event_type', 'actor_type', 'actor_id', 'subject_type', 'subject_id', 'metadata', 'ip_address', 'previous_hash', 'event_hash', 'hash_version']);

                    AuditEvent::query()->where('tenant_id', $tenant->id)
                        ->when($from !== null, fn ($query) => $query->where('occurred_at', '>=', $from))
                        ->when($to !== null, fn ($query) => $query->where('occurred_at', '<=', $to))
                        ->orderBy('chain_sequence')
                        ->chunk(500, function ($events) use ($output): void {
                            foreach ($events as $event) {
                                fputcsv($output, [
                                    $event->chain_sequence,
                                    $event->occurred_at?->toIso8601String(),
                                    $this->csvSafe($event->event_type),
                                    $this->csvSafe($event->actor_type),
                                    $this->csvSafe($event->actor_id),
                                    $this->csvSafe($event->subject_type),
                                    $this->csvSafe($event->subject_id),
                                    json_encode($event->metadata ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                                    $this->csvSafe($event->ip_address),
                                    $event->previous_hash,
                                    $event->event_hash,
                                    $event->hash_version,
                                ]);
                            }
                        });

                    fclose($output);
                });
            });
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function csvSafe(?string $value): ?string
    {
        if ($value !== null && preg_match('/^[=+@\-\t\r]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
