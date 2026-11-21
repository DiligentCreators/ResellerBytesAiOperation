<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreSafeActionResultRequest;
use App\Models\SafeActionExecution;
use App\Models\WhmcsIntegration;
use App\Services\SafeActions\SafeActionExecutor;
use Illuminate\Http\JsonResponse;

class StoreSafeActionResultController extends Controller
{
    public function __invoke(
        StoreSafeActionResultRequest $request,
        SafeActionExecution $safeAction,
        SafeActionExecutor $executor,
    ): JsonResponse {
        /** @var WhmcsIntegration $integration */
        $integration = $request->attributes->get('whmcs_integration');

        if ($safeAction->integration_id !== $integration->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $validated = $request->validated();

        $execution = $executor->recordAddonResult(
            $safeAction,
            $integration,
            (string) $validated['status'],
            (string) $validated['summary'],
            is_array($validated['evidence'] ?? null) ? $validated['evidence'] : [],
            isset($validated['error_code']) ? (string) $validated['error_code'] : null,
        );

        return response()->json([
            'data' => [
                'id' => $execution->id,
                'status' => $execution->status,
            ],
        ]);
    }
}
