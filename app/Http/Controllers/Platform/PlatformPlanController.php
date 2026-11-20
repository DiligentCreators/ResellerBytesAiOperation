<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\CentralStaffAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeBilling($request);
        Plan::query()->create($this->validated($request));

        return back()->with('status', 'Plan created.');
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $this->authorizeBilling($request);
        $plan->fill($this->validated($request, $plan))->save();

        return back()->with('status', 'Plan updated. Seeded prices remain placeholders until you confirm launch amounts.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Plan $plan = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('plans', 'code')->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_monthly_minor' => ['required', 'integer', 'min:0'],
            'price_yearly_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:90'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'kelviq_plan_identifier_monthly' => ['nullable', 'string', 'max:160'],
            'kelviq_plan_identifier_yearly' => ['nullable', 'string', 'max:160'],
            'entitlements' => ['required', 'array'],
            'entitlements.limits' => ['required', 'array'],
            'entitlements.limits.members' => ['required', 'integer', 'min:0'],
            'entitlements.limits.integrations' => ['required', 'integer', 'min:0'],
            'entitlements.limits.diagnostics_per_period' => ['required', 'integer', 'min:0'],
            'entitlements.limits.retention_days' => ['required', 'integer', 'min:1'],
            'entitlements.support_level' => ['nullable', 'string', 'max:40'],
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['is_public'] = $request->boolean('is_public');
        $data['is_contact_sales'] = $request->boolean('is_contact_sales');
        $data['currency'] = strtoupper($data['currency']);
        $data['entitlements'] = [
            ...$data['entitlements'],
            'diagnostics.analysis' => true,
            'diagnostics.reanalyze' => true,
        ];

        return $data;
    }

    private function authorizeBilling(Request $request): void
    {
        abort_unless(app(CentralStaffAuthorization::class)->can($request->user(), 'billing'), 403);
    }
}
