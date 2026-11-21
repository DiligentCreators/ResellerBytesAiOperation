import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type SecretPresentation = {
    configured: boolean;
    source: 'database' | 'env';
    mask: string | null;
};

type SettingsProps = {
    gateway: string;
    gateway_source: 'database' | 'env';
    kelviq_mode: string;
    kelviq_mode_source: 'database' | 'env';
    kelviq_sandbox_api_key: SecretPresentation;
    kelviq_live_api_key: SecretPresentation;
    kelviq_sandbox_webhook_secret: SecretPresentation;
    kelviq_live_webhook_secret: SecretPresentation;
    trials_enabled: boolean;
    trial_card_required: boolean;
    trial_first_time_only: boolean;
    webhook_url: string;
};

type Props = {
    settings: SettingsProps;
    status?: string;
    connection_test?: {
        ok: boolean;
        status: number | null;
        message: string;
    };
};

const selectClassName =
    'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm';
const checkboxLabelClassName =
    'flex items-center gap-2 text-sm text-muted-foreground';

function SourceHint({ source }: { source: 'database' | 'env' }) {
    return (
        <span className="text-xs text-muted-foreground">
            Source: {source === 'database' ? 'database' : '.env fallback'}
        </span>
    );
}

function SecretField({
    id,
    label,
    presentation,
    clearName,
    error,
}: {
    id: string;
    label: string;
    presentation: SecretPresentation;
    clearName: string;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <Label htmlFor={id}>{label}</Label>
                <SourceHint source={presentation.source} />
            </div>
            <p className="text-xs text-muted-foreground">
                {presentation.configured
                    ? `Configured (${presentation.mask ?? '••••'})`
                    : 'Not configured'}
            </p>
            <Input
                id={id}
                name={id}
                type="password"
                autoComplete="new-password"
                placeholder="Leave blank to keep current value"
            />
            <label className={checkboxLabelClassName}>
                <input type="checkbox" name={clearName} value="1" />
                Clear stored value (fall back to .env)
            </label>
            <InputError message={error} />
        </div>
    );
}

export default function PlatformBillingSettingsPage({
    settings,
    status,
    connection_test,
}: Props) {
    const [copied, setCopied] = useState(false);

    async function copyWebhookUrl() {
        try {
            await navigator.clipboard.writeText(settings.webhook_url);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
        }
    }

    return (
        <>
            <Head title="Billing settings" />
            <div className="mx-auto max-w-5xl space-y-8 p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <Link
                            href="/platform/billing"
                            className="text-sm font-semibold text-primary"
                        >
                            ← Platform billing
                        </Link>
                        <h1 className="mt-2 text-2xl font-bold tracking-tight">
                            Billing settings
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Gateway choice, Kelviq credentials, and trial rules.
                            Secrets are never shown in full.
                        </p>
                    </div>
                    <Link
                        href="/platform/billing/gateway"
                        className="text-sm font-semibold text-primary"
                    >
                        Gateway status →
                    </Link>
                </div>

                {status ? (
                    <p
                        role="status"
                        className="rounded-md border border-[#059669]/20 bg-[#ECFDF5] px-3 py-2 text-sm text-[#059669]"
                    >
                        {status}
                    </p>
                ) : null}

                {connection_test ? (
                    <p
                        role="status"
                        className={`rounded-md border px-3 py-2 text-sm ${
                            connection_test.ok
                                ? 'border-[#059669]/20 bg-[#ECFDF5] text-[#059669]'
                                : 'border-[#DC2626]/20 bg-[#FEF2F2] text-[#DC2626]'
                        }`}
                    >
                        {connection_test.message}
                        {connection_test.status !== null
                            ? ` (HTTP ${connection_test.status})`
                            : ''}
                    </p>
                ) : null}

                <Form
                    action="/platform/billing/settings"
                    method="put"
                    className="space-y-8"
                    resetOnSuccess={[
                        'kelviq_sandbox_api_key',
                        'kelviq_live_api_key',
                        'kelviq_sandbox_webhook_secret',
                        'kelviq_live_webhook_secret',
                        'clear_kelviq_sandbox_api_key',
                        'clear_kelviq_live_api_key',
                        'clear_kelviq_sandbox_webhook_secret',
                        'clear_kelviq_live_webhook_secret',
                    ]}
                >
                    {({ errors, processing }) => (
                        <>
                            <section className="space-y-4 rounded-lg border border-border bg-white p-5">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <h2 className="text-base font-semibold">
                                        Gateway
                                    </h2>
                                    <SourceHint source={settings.gateway_source} />
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <label className="flex items-center gap-3 rounded-lg border border-border p-3 text-sm">
                                        <input
                                            type="radio"
                                            name="gateway"
                                            value="sandbox"
                                            defaultChecked={
                                                settings.gateway === 'sandbox'
                                            }
                                        />
                                        Sandbox (local)
                                    </label>
                                    <label className="flex items-center gap-3 rounded-lg border border-border p-3 text-sm">
                                        <input
                                            type="radio"
                                            name="gateway"
                                            value="kelviq"
                                            defaultChecked={
                                                settings.gateway === 'kelviq'
                                            }
                                        />
                                        Kelviq
                                    </label>
                                </div>
                                <InputError message={errors.gateway} />
                            </section>

                            <section className="space-y-4 rounded-lg border border-border bg-white p-5">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <h2 className="text-base font-semibold">
                                        Kelviq
                                    </h2>
                                    <SourceHint
                                        source={settings.kelviq_mode_source}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="kelviq_mode">Mode</Label>
                                    <select
                                        id="kelviq_mode"
                                        name="kelviq_mode"
                                        defaultValue={settings.kelviq_mode}
                                        className={selectClassName}
                                    >
                                        <option value="sandbox">Sandbox</option>
                                        <option value="live">Live</option>
                                    </select>
                                    <InputError message={errors.kelviq_mode} />
                                </div>
                                <div className="grid gap-4 md:grid-cols-2">
                                    <SecretField
                                        id="kelviq_sandbox_api_key"
                                        label="Sandbox API key"
                                        presentation={
                                            settings.kelviq_sandbox_api_key
                                        }
                                        clearName="clear_kelviq_sandbox_api_key"
                                        error={errors.kelviq_sandbox_api_key}
                                    />
                                    <SecretField
                                        id="kelviq_live_api_key"
                                        label="Live API key"
                                        presentation={
                                            settings.kelviq_live_api_key
                                        }
                                        clearName="clear_kelviq_live_api_key"
                                        error={errors.kelviq_live_api_key}
                                    />
                                    <SecretField
                                        id="kelviq_sandbox_webhook_secret"
                                        label="Sandbox webhook secret"
                                        presentation={
                                            settings.kelviq_sandbox_webhook_secret
                                        }
                                        clearName="clear_kelviq_sandbox_webhook_secret"
                                        error={
                                            errors.kelviq_sandbox_webhook_secret
                                        }
                                    />
                                    <SecretField
                                        id="kelviq_live_webhook_secret"
                                        label="Live webhook secret"
                                        presentation={
                                            settings.kelviq_live_webhook_secret
                                        }
                                        clearName="clear_kelviq_live_webhook_secret"
                                        error={
                                            errors.kelviq_live_webhook_secret
                                        }
                                    />
                                </div>
                            </section>

                            <section className="space-y-3 rounded-lg border border-border bg-white p-5">
                                <h2 className="text-base font-semibold">
                                    Webhook URL
                                </h2>
                                <p className="font-mono text-sm break-all">
                                    {settings.webhook_url}
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="border-border text-primary"
                                    onClick={copyWebhookUrl}
                                >
                                    {copied ? 'Copied' : 'Copy URL'}
                                </Button>
                            </section>

                            <section className="space-y-4 rounded-lg border border-border bg-white p-5">
                                <h2 className="text-base font-semibold">
                                    Trial rules (global)
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Per-plan trial days arrive in a later
                                    chunk. These flags are stored now for the
                                    resolver.
                                </p>
                                <div className="grid gap-4 md:grid-cols-3">
                                    <div className="grid gap-2">
                                        <Label htmlFor="trials_enabled">
                                            Trials enabled
                                        </Label>
                                        <select
                                            id="trials_enabled"
                                            name="trials_enabled"
                                            defaultValue={
                                                settings.trials_enabled
                                                    ? '1'
                                                    : '0'
                                            }
                                            className={selectClassName}
                                        >
                                            <option value="1">Yes</option>
                                            <option value="0">No</option>
                                        </select>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="trial_card_required">
                                            Card required
                                        </Label>
                                        <select
                                            id="trial_card_required"
                                            name="trial_card_required"
                                            defaultValue={
                                                settings.trial_card_required
                                                    ? '1'
                                                    : '0'
                                            }
                                            className={selectClassName}
                                        >
                                            <option value="1">Yes</option>
                                            <option value="0">No</option>
                                        </select>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="trial_first_time_only">
                                            First-time workspaces only
                                        </Label>
                                        <select
                                            id="trial_first_time_only"
                                            name="trial_first_time_only"
                                            defaultValue={
                                                settings.trial_first_time_only
                                                    ? '1'
                                                    : '0'
                                            }
                                            className={selectClassName}
                                        >
                                            <option value="1">Yes</option>
                                            <option value="0">No</option>
                                        </select>
                                    </div>
                                </div>
                            </section>

                            <div className="flex flex-wrap gap-3">
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Saving…' : 'Save settings'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <Form
                    action="/platform/billing/settings/test-connection"
                    method="post"
                    className="rounded-lg border border-border bg-white p-5"
                >
                    {({ processing }) => (
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="text-base font-semibold">
                                    Test connection
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Calls Kelviq GET /webhook/logs/?page_size=1
                                    with the resolved key for the current mode.
                                </p>
                            </div>
                            <Button
                                type="submit"
                                variant="outline"
                                className="border-border text-primary"
                                disabled={processing}
                            >
                                {processing ? 'Testing…' : 'Test connection'}
                            </Button>
                        </div>
                    )}
                </Form>
            </div>
        </>
    );
}
