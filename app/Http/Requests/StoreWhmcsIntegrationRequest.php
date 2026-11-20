<?php

namespace App\Http\Requests;

use App\Models\TenantMembership;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreWhmcsIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'tenant_id' => ['nullable', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $tenantId = $this->resolvedTenantId();
            if ($tenantId === null) {
                $validator->errors()->add('tenant_id', 'An active workspace with integration management permission is required.');

                return;
            }

            try {
                app(TenantRoleAuthorization::class)->require($this->user(), $tenantId, 'integrations.manage');
            } catch (\Throwable) {
                $validator->errors()->add('tenant_id', 'You are not allowed to manage integrations for this workspace.');
            }
        });
    }

    public function resolvedTenantId(): ?string
    {
        $explicit = trim((string) $this->input('tenant_id', ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $manageable = $this->user()
            ?->tenantMemberships()
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->get()
            ->filter(fn (TenantMembership $membership): bool => app(TenantRoleAuthorization::class)->allows($membership, 'integrations.manage'))
            ->values();

        if ($manageable === null || $manageable->count() !== 1) {
            return null;
        }

        return (string) $manageable->first()->tenant_id;
    }
}
