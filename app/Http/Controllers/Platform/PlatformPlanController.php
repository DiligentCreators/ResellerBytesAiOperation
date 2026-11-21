<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlatformPlanRequest;
use App\Http\Requests\Platform\UpdatePlatformPlanRequest;
use App\Models\Plan;
use App\Services\Audit\AuditEventWriter;
use App\Services\CentralStaffAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PlatformPlanController extends Controller
{
    /**
     * @var list<string>
     */
    private const AUDITABLE_FIELDS = [
        'code',
        'name',
        'description',
        'price_monthly_minor',
        'price_yearly_minor',
        'currency',
        'trial_days',
        'trial_enabled',
        'features',
        'entitlements',
        'sort_order',
        'is_active',
        'is_public',
        'is_contact_sales',
        'monthly_active',
        'yearly_active',
        'prices_confirmed_at',
        'kelviq_plan_identifier_monthly',
        'kelviq_plan_identifier_yearly',
    ];

    public function index(Request $request): Response
    {
        $this->authorizeBilling($request);
        $canManage = app(CentralStaffAuthorization::class)->can($request->user(), 'billing.settings.manage');

        return Inertia::render('platform/billing/plans', [
            'plans' => Plan::query()->orderBy('sort_order')->orderBy('id')->get()->map(
                fn (Plan $plan): array => $this->planToArray($plan),
            ),
            'pricesArePlaceholders' => Plan::catalogHasUnconfirmedPrices(),
            'canManage' => $canManage,
        ]);
    }

    public function store(StorePlatformPlanRequest $request, AuditEventWriter $audit): RedirectResponse
    {
        $payload = $request->planPayload();

        DB::transaction(function () use ($payload, $request, $audit): void {
            $plan = Plan::query()->create($payload);

            $audit->append([
                'actor_type' => 'user',
                'actor_id' => (string) $request->user()->id,
                'event_type' => 'billing.plan.created',
                'subject_type' => Plan::class,
                'subject_id' => (string) $plan->id,
                'metadata' => [
                    'changed_fields' => $this->changedFields(null, $plan),
                ],
                'ip_address' => $request->ip(),
                'occurred_at' => now(),
            ]);
        });

        return back()->with('status', 'Plan created.');
    }

    public function update(UpdatePlatformPlanRequest $request, Plan $plan, AuditEventWriter $audit): RedirectResponse
    {
        $payload = $request->planPayload();
        // Defense in depth: never overwrite a billing-referenced plan code from client input.
        if ($plan->isReferencedByBilling()) {
            unset($payload['code']);
        }
        $before = $plan->replicate();

        DB::transaction(function () use ($plan, $payload, $before, $request, $audit): void {
            $plan->fill($payload)->save();

            $changes = $this->changedFields($before, $plan->fresh());
            if ($changes !== []) {
                $audit->append([
                    'actor_type' => 'user',
                    'actor_id' => (string) $request->user()->id,
                    'event_type' => 'billing.plan.updated',
                    'subject_type' => Plan::class,
                    'subject_id' => (string) $plan->id,
                    'metadata' => [
                        'changed_fields' => $changes,
                    ],
                    'ip_address' => $request->ip(),
                    'occurred_at' => now(),
                ]);
            }
        });

        return back()->with('status', 'Plan updated. Price changes apply to new checkouts and plan changes from now on.');
    }

    public function deactivate(Request $request, Plan $plan, AuditEventWriter $audit): RedirectResponse
    {
        $this->authorizeManage($request);

        if (! $plan->is_active) {
            return back()->with('status', 'Plan is already deactivated.');
        }

        DB::transaction(function () use ($plan, $request, $audit): void {
            $plan->forceFill(['is_active' => false])->save();

            $audit->append([
                'actor_type' => 'user',
                'actor_id' => (string) $request->user()->id,
                'event_type' => 'billing.plan.deactivated',
                'subject_type' => Plan::class,
                'subject_id' => (string) $plan->id,
                'metadata' => [
                    'changed_fields' => [
                        'is_active' => ['old' => true, 'new' => false],
                    ],
                ],
                'ip_address' => $request->ip(),
                'occurred_at' => now(),
            ]);
        });

        return back()->with('status', 'Plan deactivated. Existing subscriptions continue unchanged.');
    }

    public function reactivate(Request $request, Plan $plan, AuditEventWriter $audit): RedirectResponse
    {
        $this->authorizeManage($request);

        if ($plan->is_active) {
            return back()->with('status', 'Plan is already active.');
        }

        DB::transaction(function () use ($plan, $request, $audit): void {
            $plan->forceFill(['is_active' => true])->save();

            $audit->append([
                'actor_type' => 'user',
                'actor_id' => (string) $request->user()->id,
                'event_type' => 'billing.plan.reactivated',
                'subject_type' => Plan::class,
                'subject_id' => (string) $plan->id,
                'metadata' => [
                    'changed_fields' => [
                        'is_active' => ['old' => false, 'new' => true],
                    ],
                ],
                'ip_address' => $request->ip(),
                'occurred_at' => now(),
            ]);
        });

        return back()->with('status', 'Plan reactivated.');
    }

    public function destroy(Request $request, Plan $plan, AuditEventWriter $audit): RedirectResponse
    {
        $this->authorizeManage($request);

        if ($plan->isReferencedByBilling()) {
            throw ValidationException::withMessages([
                'plan' => 'This plan is referenced by a subscription (or scheduled plan change). Deactivate it instead of deleting.',
            ]);
        }

        DB::transaction(function () use ($plan, $request, $audit): void {
            $snapshot = $this->changedFields(null, $plan);
            $planId = (string) $plan->id;
            $plan->delete();

            $audit->append([
                'actor_type' => 'user',
                'actor_id' => (string) $request->user()->id,
                'event_type' => 'billing.plan.deleted',
                'subject_type' => Plan::class,
                'subject_id' => $planId,
                'metadata' => [
                    'changed_fields' => $snapshot,
                ],
                'ip_address' => $request->ip(),
                'occurred_at' => now(),
            ]);
        });

        return back()->with('status', 'Plan deleted.');
    }

    private function authorizeBilling(Request $request): void
    {
        abort_unless(app(CentralStaffAuthorization::class)->can($request->user(), 'billing'), 403);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless(
            app(CentralStaffAuthorization::class)->can($request->user(), 'billing.settings.manage'),
            403,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function planToArray(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'price_monthly_minor' => $plan->price_monthly_minor,
            'price_yearly_minor' => $plan->price_yearly_minor,
            'price_monthly' => number_format($plan->price_monthly_minor / 100, 2, '.', ''),
            'price_yearly' => number_format($plan->price_yearly_minor / 100, 2, '.', ''),
            'currency' => $plan->currency,
            'trial_days' => $plan->trial_days,
            'trial_enabled' => (bool) $plan->trial_enabled,
            'features' => is_array($plan->features) ? $plan->features : [],
            'sort_order' => $plan->sort_order,
            'is_active' => $plan->is_active,
            'is_public' => $plan->is_public,
            'is_contact_sales' => $plan->is_contact_sales,
            'monthly_active' => (bool) $plan->monthly_active,
            'yearly_active' => (bool) $plan->yearly_active,
            'prices_confirmed_at' => $plan->prices_confirmed_at?->toIso8601String(),
            'code_locked' => $plan->isReferencedByBilling(),
            'kelviq_plan_identifier_monthly' => $plan->kelviq_plan_identifier_monthly,
            'kelviq_plan_identifier_yearly' => $plan->kelviq_plan_identifier_yearly,
            'entitlements' => $plan->entitlements,
        ];
    }

    /**
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changedFields(?Plan $before, Plan $after): array
    {
        $changes = [];

        foreach (self::AUDITABLE_FIELDS as $field) {
            $old = $before?->{$field};
            $new = $after->{$field};

            if ($old instanceof \DateTimeInterface) {
                $old = $old->format(DATE_ATOM);
            }
            if ($new instanceof \DateTimeInterface) {
                $new = $new->format(DATE_ATOM);
            }

            if ($before === null || $old != $new) {
                $changes[$field] = [
                    'old' => $before === null ? null : $old,
                    'new' => $new,
                ];
            }
        }

        return $changes;
    }
}
