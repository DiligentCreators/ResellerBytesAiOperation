<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlatformPlanRequest;
use App\Http\Requests\Platform\UpdatePlatformPlanRequest;
use App\Models\Plan;
use App\Services\CentralStaffAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlatformPlanController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeBilling($request);

        return Inertia::render('platform/billing/plans', [
            'plans' => Plan::query()->orderBy('sort_order')->orderBy('id')->get(),
            'pricesArePlaceholders' => true,
        ]);
    }

    public function store(StorePlatformPlanRequest $request): RedirectResponse
    {
        $this->authorizeBilling($request);
        Plan::query()->create($request->planPayload());

        return back()->with('status', 'Plan created.');
    }

    public function update(UpdatePlatformPlanRequest $request, Plan $plan): RedirectResponse
    {
        $this->authorizeBilling($request);
        $plan->fill($request->planPayload())->save();

        return back()->with('status', 'Plan updated. Seeded prices remain placeholders until you confirm launch amounts.');
    }

    private function authorizeBilling(Request $request): void
    {
        abort_unless(app(CentralStaffAuthorization::class)->can($request->user(), 'billing'), 403);
    }
}
