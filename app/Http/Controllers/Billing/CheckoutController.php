<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Tenancy\TenantRoleAuthorization;
use App\Support\CountryCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class CheckoutController extends Controller
{
    public function show(Request $request, Tenant $tenant, Plan $plan): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'billing.manage');
        abort_unless($plan->is_active && ! $plan->is_contact_sales, 404);

        $period = $request->string('period')->toString() === 'yearly' ? 'yearly' : 'monthly';
        $billing = (array) (($tenant->settings['billing'] ?? []) ?: []);

        return Inertia::render('billing/checkout', [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'plan' => [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $plan->description,
                'price_monthly_minor' => $plan->price_monthly_minor,
                'price_yearly_minor' => $plan->price_yearly_minor,
                'currency' => $plan->currency,
                'trial_days' => $plan->trial_days,
            ],
            'period' => $period,
            'countries' => CountryCodes::options(),
            'billingDetails' => [
                'company_name' => $billing['company_name'] ?? $tenant->name,
                'billing_email' => $billing['billing_email'] ?? $user->email,
                'country' => $billing['country'] ?? $tenant->country_code ?? 'US',
                'address_line1' => $billing['address_line1'] ?? '',
                'address_line2' => $billing['address_line2'] ?? '',
                'city' => $billing['city'] ?? '',
                'state' => $billing['state'] ?? '',
                'postal_code' => $billing['postal_code'] ?? '',
                'tax_id' => $billing['tax_id'] ?? '',
            ],
            'pricesArePlaceholders' => true,
        ]);
    }

    public function store(Request $request, Tenant $tenant, Plan $plan): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $data = $request->validate([
            'period' => ['required', Rule::in(['monthly', 'yearly'])],
            'company_name' => ['required', 'string', 'max:160'],
            'billing_email' => ['required', 'email:rfc', 'max:255'],
            'country' => ['required', 'string', 'size:2'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:40'],
            'tax_id' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $result = app(CheckoutService::class)->begin(
                $tenant->id,
                $user,
                $plan,
                $data['period'],
                [
                    'company_name' => $data['company_name'],
                    'billing_email' => $data['billing_email'],
                    'country' => strtoupper($data['country']),
                    'address_line1' => $data['address_line1'],
                    'address_line2' => $data['address_line2'] ?? null,
                    'city' => $data['city'] ?? null,
                    'state' => $data['state'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null,
                    'tax_id' => $data['tax_id'] ?? null,
                ],
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'checkout' => 'Checkout could not be started. Confirm you have billing permission and no active subscription already exists.',
            ]);
        }

        return redirect()->away($result['checkout_url']);
    }
}
