import { Form, Head, Link, usePage } from '@inertiajs/react';
import { CheckCircle2, Circle } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    completePlan,
    completeWhmcs,
    updateWorkspace,
} from '@/actions/App/Http/Controllers/OnboardingController';
import { edit as editSecurity } from '@/routes/security';

type Props = {
    tenant: {
        id: string;
        name: string;
        country_code: string | null;
        timezone: string;
        require_two_factor: boolean;
    };
    steps: {
        workspace: boolean;
        plan: boolean;
        whmcs: boolean;
    };
    currentStep: 'workspace' | 'plan' | 'whmcs' | 'done';
    timezones: string[];
    plansUrl: string;
    integrationsUrl: string;
    addonSetupUrl: string;
};

function StepMarker({ done, label }: { done: boolean; label: string }) {
    return (
        <div className="flex items-center gap-2 text-sm">
            {done ? (
                <CheckCircle2 className="size-4 text-emerald-600" />
            ) : (
                <Circle className="size-4 text-muted-foreground" />
            )}
            <span className={done ? 'font-medium' : 'text-muted-foreground'}>
                {label}
            </span>
        </div>
    );
}

export default function OnboardingIndex({
    tenant,
    steps,
    currentStep,
    timezones,
    plansUrl,
    integrationsUrl,
    addonSetupUrl,
}: Props) {
    const page = usePage().props;
    const twoFactorEnabled = Boolean(page.auth.two_factor_enabled);
    const twoFactorRequiredByPlatform = Boolean(
        page.security?.twoFactorRequiredByPlatform,
    );

    return (
        <>
            <Head title="Workspace onboarding" />
            <div className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-8">
                <div>
                    <p className="text-sm font-medium tracking-wide text-muted-foreground uppercase">
                        Get started
                    </p>
                    <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                        Set up {tenant.name}
                    </h1>
                    <p className="mt-2 text-muted-foreground">
                        Three short steps: confirm workspace details, choose a
                        plan, then connect WHMCS.
                    </p>
                </div>

                <div className="flex flex-wrap gap-4 border-b pb-4">
                    <StepMarker done={steps.workspace} label="1. Workspace" />
                    <StepMarker done={steps.plan} label="2. Plan" />
                    <StepMarker done={steps.whmcs} label="3. WHMCS" />
                </div>

                {currentStep === 'workspace' && (
                    <Form
                        {...updateWorkspace.form()}
                        className="flex flex-col gap-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Workspace name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        defaultValue={tenant.name}
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="timezone">Timezone</Label>
                                    <select
                                        id="timezone"
                                        name="timezone"
                                        required
                                        defaultValue={tenant.timezone}
                                        className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    >
                                        {timezones.map((timezone) => (
                                            <option
                                                key={timezone}
                                                value={timezone}
                                            >
                                                {timezone}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.timezone} />
                                </div>
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="require_two_factor"
                                        name="require_two_factor"
                                        value="1"
                                        defaultChecked={
                                            tenant.require_two_factor
                                        }
                                        className="mt-0.5"
                                    />
                                    <div className="space-y-1">
                                        <Label
                                            htmlFor="require_two_factor"
                                            className="text-sm leading-5 font-normal"
                                        >
                                            Require two-factor authentication
                                            for everyone on this workspace
                                        </Label>
                                        {!twoFactorRequiredByPlatform ? (
                                            <p className="text-xs text-muted-foreground">
                                                Not enforced while platform 2FA
                                                requirement is off.
                                            </p>
                                        ) : null}
                                    </div>
                                </div>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Continue
                                </Button>
                            </>
                        )}
                    </Form>
                )}

                {currentStep === 'plan' && (
                    <div className="flex flex-col gap-5">
                        <p className="text-sm text-muted-foreground">
                            Review available plans. Full checkout ships in the
                            next release; for now, open the plans page and
                            continue.
                        </p>
                        <div className="flex flex-wrap gap-3">
                            <Button asChild variant="outline">
                                <Link href={plansUrl}>View plans</Link>
                            </Button>
                            <Form {...completePlan.form()}>
                                {({ processing }) => (
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        Continue
                                    </Button>
                                )}
                            </Form>
                        </div>
                    </div>
                )}

                {currentStep === 'whmcs' && (
                    <div className="flex flex-col gap-5">
                        <p className="text-sm text-muted-foreground">
                            Connect your WHMCS install with an integration and
                            the signed addon package. You can finish the
                            technical install later — mark this step when you
                            are ready to proceed.
                        </p>
                        {!twoFactorEnabled ? (
                            <div
                                role="status"
                                className="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
                            >
                                <p>
                                    Creating an integration requires the
                                    workspace owner to confirm two-factor
                                    authentication (TOTP) first.
                                </p>
                                <Link
                                    href={editSecurity()}
                                    className="mt-2 inline-flex font-medium underline underline-offset-4 hover:no-underline"
                                >
                                    Enable 2FA
                                </Link>
                            </div>
                        ) : null}
                        <div className="flex flex-wrap gap-3">
                            <Button asChild variant="outline">
                                <Link href={integrationsUrl}>Integrations</Link>
                            </Button>
                            <Button asChild variant="outline">
                                <Link href={addonSetupUrl}>
                                    WHMCS addon setup
                                </Link>
                            </Button>
                            <Form {...completeWhmcs.form()}>
                                {({ processing }) => (
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        Finish onboarding
                                    </Button>
                                )}
                            </Form>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}
