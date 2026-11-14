<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('plan_code', 64);
            $table->string('billing_mode', 16);
            $table->string('status', 20)->index();
            $table->string('billing_provider', 40)->nullable();
            $table->string('provider_subscription_id', 160)->nullable()->unique();
            $table->string('currency', 3);
            $table->unsignedBigInteger('unit_amount_minor');
            $table->jsonb('entitlements');
            $table->timestampTz('current_period_starts_at');
            $table->timestampTz('current_period_ends_at');
            $table->timestampTz('grace_ends_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'current_period_ends_at'], 'tenant_subscription_entitlement_lookup');
        });

        Schema::create('tenant_billing_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('tenant_subscription_id')->nullable()->constrained('tenant_subscriptions')->restrictOnDelete();
            $table->string('invoice_number', 80)->unique();
            $table->string('status', 20)->index();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_due_minor');
            $table->unsignedBigInteger('amount_paid_minor')->default(0);
            $table->timestampTz('issued_at');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'due_at']);
        });

        Schema::create('tenant_billing_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('tenant_billing_invoice_id')->constrained('tenant_billing_invoices')->restrictOnDelete();
            $table->string('collection_mode', 16);
            $table->string('idempotency_key', 120)->unique();
            $table->string('provider', 40)->nullable();
            $table->string('provider_payment_id', 160)->nullable()->unique();
            $table->string('status', 20)->index();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 160)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('received_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'received_at']);
            $table->unique(['id', 'tenant_id'], 'tenant_billing_payment_id_tenant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_billing_payments');
        Schema::dropIfExists('tenant_billing_invoices');
        Schema::dropIfExists('tenant_subscriptions');
    }
};
