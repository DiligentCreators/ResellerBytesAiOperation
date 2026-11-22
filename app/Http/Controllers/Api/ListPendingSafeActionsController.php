<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SafeActionExecution;
use App\Models\WhmcsIntegration;
use App\Services\SafeActions\SafeActionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListPendingSafeActionsController extends Controller
{
    public function __invoke(Request $request, SafeActionRegistry $registry): JsonResponse
    {
        /** @var WhmcsIntegration $integration */
        $integration = $request->attributes->get('whmcs_integration');

        $executions = SafeActionExecution::query()
            ->where('integration_id', $integration->id)
            ->where('status', SafeActionExecution::STATUS_AWAITING_AGENT)
            ->orderBy('approved_at')
            ->limit(20)
            ->get();

        $unpickedIds = $executions
            ->filter(fn (SafeActionExecution $execution): bool => $execution->agent_picked_up_at === null)
            ->pluck('id')
            ->all();

        if ($unpickedIds !== []) {
            $pickedUpAt = now();
            SafeActionExecution::query()
                ->whereIn('id', $unpickedIds)
                ->whereNull('agent_picked_up_at')
                ->update(['agent_picked_up_at' => $pickedUpAt]);

            foreach ($executions as $execution) {
                if (in_array($execution->id, $unpickedIds, true)) {
                    $execution->forceFill(['agent_picked_up_at' => $pickedUpAt]);
                }
            }
        }

        $host = $this->hostPayload($integration);

        $actions = $executions->map(function (SafeActionExecution $execution) use ($registry, $host): array {
            $definition = $registry->get($execution->action_key);
            $needsHost = in_array($execution->action_key, [
                'disable_php_display_errors',
                'harden_configuration_php_mode',
            ], true);

            return [
                'id' => $execution->id,
                'action_key' => $execution->action_key,
                'label' => $definition['label'],
                'finding_id' => $execution->finding_id,
                'issue_key' => $execution->issue_key,
                'mode' => $execution->mode,
                'batch_id' => $execution->batch_id,
                'target_ids' => $execution->target_ids ?? [],
                'preview' => $execution->preview ?? [],
                'resolved_params' => $execution->resolved_params ?? [],
                'input' => $execution->input ?? [],
                'approved_at' => $execution->approved_at?->toIso8601String(),
                'host' => $needsHost ? $host : null,
            ];
        })->values()->all();

        return response()->json([
            'data' => [
                'actions' => $actions,
                'host_credentials_configured' => $host !== null,
            ],
        ]);
    }

    /**
     * @return array{
     *     protocol: string,
     *     host: string,
     *     port: int,
     *     username: string,
     *     password: string,
     *     remote_whmcs_root: string
     * }|null
     */
    private function hostPayload(WhmcsIntegration $integration): ?array
    {
        $connection = $integration->host_connection;
        if (! is_array($connection)) {
            return null;
        }

        $host = $connection['host'] ?? null;
        $username = $connection['username'] ?? null;
        $password = $connection['password'] ?? null;
        $root = $connection['remote_whmcs_root'] ?? null;

        if (! is_string($host) || $host === ''
            || ! is_string($username) || $username === ''
            || ! is_string($password) || $password === ''
            || ! is_string($root) || $root === '') {
            return null;
        }

        return [
            'protocol' => in_array($connection['protocol'] ?? 'ftp', ['ftp', 'ftps'], true)
                ? (string) $connection['protocol']
                : 'ftp',
            'host' => $host,
            'port' => (int) ($connection['port'] ?? 21),
            'username' => $username,
            'password' => $password,
            'remote_whmcs_root' => $root,
        ];
    }
}
