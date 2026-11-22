import { Form, Head } from '@inertiajs/react';
import { Clock3, Palette } from 'lucide-react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Branding = {
    display_name?: string;
    support_email?: string | null;
    primary_color?: string;
    logo_url?: string | null;
};

type Props = {
    tenant: { id: string; name: string; hourly_rate_usd?: number | null };
    branding: Branding;
    hourly_rate?: {
        value: number | null;
        effective: number;
        platform_default: number;
    };
    canManage: boolean;
    status?: string;
};

export default function TenantSettings({
    tenant,
    branding,
    hourly_rate,
    canManage,
    status,
}: Props) {
    return (
        <>
            <Head title="Workspace settings" />
            <div className="space-y-6">
                <PageHeader
                    title="Workspace settings"
                    description={`Branding and display preferences for ${tenant.name}.`}
                />

                {status ? (
                    <p
                        role="status"
                        className="rounded-md border border-success/20 bg-success-soft px-3 py-2 text-sm text-success-fg"
                    >
                        {status}
                    </p>
                ) : null}

                <section className="rounded-lg border border-border bg-card shadow-xs">
                    <div className="flex items-center gap-2 border-b border-border px-5 py-4">
                        <Palette
                            className="size-4 text-slate-brand"
                            aria-hidden
                        />
                        <h2 className="text-sm font-semibold">Branding</h2>
                    </div>
                    <Form
                        action={`/tenants/${tenant.id}/settings/branding`}
                        method="put"
                        className="space-y-5 p-5"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="display_name">
                                        Display name
                                    </Label>
                                    <Input
                                        id="display_name"
                                        name="display_name"
                                        defaultValue={
                                            branding.display_name ?? tenant.name
                                        }
                                        disabled={!canManage}
                                    />
                                    <InputError message={errors.display_name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="support_email">
                                        Support email
                                    </Label>
                                    <Input
                                        id="support_email"
                                        name="support_email"
                                        type="email"
                                        defaultValue={
                                            branding.support_email ?? ''
                                        }
                                        disabled={!canManage}
                                    />
                                    <InputError
                                        message={errors.support_email}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="primary_color">
                                        Primary color
                                    </Label>
                                    <Input
                                        id="primary_color"
                                        name="primary_color"
                                        defaultValue={
                                            branding.primary_color ?? '#0779A8'
                                        }
                                        disabled={!canManage}
                                        placeholder="#0779A8"
                                    />
                                    <InputError
                                        message={errors.primary_color}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="logo_url">
                                        Logo URL (https)
                                    </Label>
                                    <Input
                                        id="logo_url"
                                        name="logo_url"
                                        type="url"
                                        defaultValue={branding.logo_url ?? ''}
                                        disabled={!canManage}
                                    />
                                    <InputError message={errors.logo_url} />
                                </div>
                                {canManage ? (
                                    <div className="-mx-5 -mb-5 flex justify-end rounded-b-lg border-t border-border bg-surface px-5 py-3">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Save branding
                                        </Button>
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        You can view branding, but only owners
                                        and admins can change it.
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                </section>

                <section className="rounded-lg border border-border bg-card shadow-xs">
                    <div className="flex items-center gap-2 border-b border-border px-5 py-4">
                        <Clock3
                            className="size-4 text-slate-brand"
                            aria-hidden
                        />
                        <h2 className="text-sm font-semibold">
                            Time-saved hourly rate
                        </h2>
                    </div>
                    <Form
                        action={`/tenants/${tenant.id}/settings/hourly-rate`}
                        method="put"
                        className="space-y-5 p-5"
                    >
                        {({ errors, processing }) => (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    Used to estimate dollar value of verified
                                    fixes on Home. Leave blank to use the
                                    platform default ($
                                    {hourly_rate?.platform_default ?? 25}
                                    /hr). Effective rate now: $
                                    {hourly_rate?.effective ?? 25}/hr.
                                </p>
                                <div className="grid gap-2">
                                    <Label htmlFor="hourly_rate_usd">
                                        Workspace hourly rate (USD)
                                    </Label>
                                    <Input
                                        id="hourly_rate_usd"
                                        name="hourly_rate_usd"
                                        type="number"
                                        min={1}
                                        max={10000}
                                        defaultValue={
                                            hourly_rate?.value ?? ''
                                        }
                                        disabled={!canManage}
                                        placeholder={String(
                                            hourly_rate?.platform_default ??
                                                25,
                                        )}
                                    />
                                    <InputError
                                        message={errors.hourly_rate_usd}
                                    />
                                </div>
                                {canManage ? (
                                    <div className="-mx-5 -mb-5 flex justify-end rounded-b-lg border-t border-border bg-surface px-5 py-3">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Save hourly rate
                                        </Button>
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        Only owners and admins can change the
                                        hourly rate.
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                </section>
            </div>
        </>
    );
}
