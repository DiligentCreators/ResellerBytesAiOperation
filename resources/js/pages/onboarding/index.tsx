import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    Circle,
    Download,
    LoaderCircle,
    Server,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    completeFirstScan,
    completePlan,
    completeWhmcs,
    updateWorkspace,
} from '@/actions/App/Http/Controllers/OnboardingController';
import { edit as editSecurity } from '@/routes/security';

type HeartbeatStatus = {
    connected: boolean;
    last_heartbeat_at: string | null;
    waiting_started_at: string | null;
    waiting_seconds: number;
    show_troubleshooting: boolean;
};

type FirstScan = {
    ready: boolean;
    issues_count: number;
    one_click_count: number;
    has_analyzed_run: boolean;
};

type Props = {
    tenant: {
        id: string;
        name: string;
        country_code: string | null;
        timezone: string;
        require_two_factor: boolean;
        alert_email: string | null;
    };
    steps: {
        workspace: boolean;
        plan: boolean;
        whmcs: boolean;
        first_scan: boolean;
    };
    currentStep: 'workspace' | 'plan' | 'whmcs' | 'first_scan' | 'done';
    firstScan: FirstScan | null;
    heartbeat: HeartbeatStatus;
    heartbeatStatusUrl: string;
    timezones: string[];
    plansUrl: string;
    integrationsUrl: string;
    addonSetupUrl: string;
    addonDownloadUrl: string;
    fixCenterUrl: string;
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

function FirstScanWaiter({
    statusUrl,
    fixCenterUrl,
}: {
    statusUrl: string;
    fixCenterUrl: string;
}) {
    useEffect(() => {
        const tick = () => {
            fetch(statusUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            })
                .then(async (response) => {
                    if (!response.ok) {
                        return;
                    }

                    const payload = (await response.json()) as {
                        first_scan?: FirstScan | null;
                    };

                    if (payload.first_scan?.ready) {
                        router.reload({
                            only: ['steps', 'currentStep', 'firstScan'],
                        });
                    }
                })
                .catch(() => {
                    // Keep waiting; the next poll will retry.
                });
        };

        const id = window.setInterval(tick, 5000);
        tick();

        return () => window.clearInterval(id);
    }, [statusUrl]);

    return (
        <div className="space-y-3 rounded-md border border-border bg-surface/60 px-4 py-3">
            <div className="flex items-center gap-2 text-sm font-medium">
                <LoaderCircle
                    className="size-4 animate-spin text-brand"
                    aria-hidden
                />
                Waiting for the first diagnostic scan
            </div>
            <p className="text-sm text-muted-foreground">
                Run a manual diagnostic from the WHMCS addon once. When the scan
                is processed, we will show the rule findings we detected.
            </p>
            <Button asChild size="sm" variant="outline">
                <Link href={fixCenterUrl}>Preview Fix Center</Link>
            </Button>
        </div>
    );
}

function HeartbeatChecker({
    initial,
    statusUrl,
}: {
    initial: HeartbeatStatus;
    statusUrl: string;
}) {
    const [status, setStatus] = useState(initial);

    useEffect(() => {
        if (status.connected) {
            return;
        }

        const tick = () => {
            fetch(statusUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            })
                .then(async (response) => {
                    if (!response.ok) {
                        return;
                    }

                    const payload =
                        (await response.json()) as HeartbeatStatus & {
                            whmcs_step_complete?: boolean;
                        };

                    setStatus(payload);

                    if (payload.connected || payload.whmcs_step_complete) {
                        router.reload({
                            only: [
                                'steps',
                                'currentStep',
                                'heartbeat',
                                'firstScan',
                            ],
                        });
                    }
                })
                .catch(() => {
                    // Keep waiting; the next poll will retry.
                });
        };

        const id = window.setInterval(tick, 5000);
        tick();

        return () => window.clearInterval(id);
    }, [status.connected, statusUrl]);

    if (status.connected) {
        return (
            <div
                role="status"
                className="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100"
            >
                <div className="flex items-center gap-2 font-medium">
                    <CheckCircle2 className="size-4" aria-hidden />
                    First heartbeat received
                </div>
                <p className="mt-1 text-muted-foreground dark:text-emerald-100/80">
                    Your WHMCS install is talking to ResellerBytes. Continue to
                    the first scan.
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-3 rounded-md border border-border bg-surface/60 px-4 py-3">
            <div className="flex items-center gap-2 text-sm font-medium">
                <LoaderCircle
                    className="size-4 animate-spin text-brand"
                    aria-hidden
                />
                Waiting for first heartbeat
            </div>
            <p className="text-sm text-muted-foreground">
                Checks every 5 seconds. Keep the WHMCS cron running and confirm
                the addon can reach this app over HTTPS.
            </p>
            {status.show_troubleshooting ? (
                <div
                    role="status"
                    className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
                >
                    <p className="font-medium">
                        Still waiting after 10 minutes
                    </p>
                    <ul className="mt-2 list-disc space-y-1 pl-5">
                        <li>Confirm the WHMCS system cron is running.</li>
                        <li>
                            Check outbound HTTPS from the WHMCS server is not
                            blocked by a firewall.
                        </li>
                        <li>
                            Re-check the credentials file path and that the
                            addon is activated.
                        </li>
                    </ul>
                </div>
            ) : null}
        </div>
    );
}

export default function OnboardingIndex({
    tenant,
    steps,
    currentStep,
    firstScan,
    heartbeat,
    heartbeatStatusUrl,
    timezones,
    plansUrl,
    integrationsUrl,
    addonSetupUrl,
    addonDownloadUrl,
    fixCenterUrl,
}: Props) {
    const page = usePage().props;
    const twoFactorEnabled = Boolean(page.auth.two_factor_enabled);
    const twoFactorRequiredByPlatform = Boolean(
        page.security?.twoFactorRequiredByPlatform,
    );
    const credentials = page.flash?.integration_credentials ?? null;

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
                        Four short steps: workspace details, plan or trial,
                        connect WHMCS, then review your first scan.
                    </p>
                </div>

                <div className="flex flex-wrap gap-4 border-b pb-4">
                    <StepMarker done={steps.workspace} label="1. Workspace" />
                    <StepMarker done={steps.plan} label="2. Plan" />
                    <StepMarker done={steps.whmcs} label="3. Connect WHMCS" />
                    <StepMarker done={steps.first_scan} label="4. First scan" />
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
                                <div className="grid gap-2">
                                    <Label htmlFor="alert_email">
                                        Alert email
                                    </Label>
                                    <Input
                                        id="alert_email"
                                        name="alert_email"
                                        type="email"
                                        required
                                        defaultValue={tenant.alert_email ?? ''}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Used for workspace alerts and digests.
                                    </p>
                                    <InputError message={errors.alert_email} />
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
                            Review available plans or start a trial. Checkout
                            stays on the plans page.
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
                            Download the signed addon, create a connection, set
                            up the WHMCS system cron, then wait for the first
                            heartbeat.
                        </p>

                        {!twoFactorEnabled ? (
                            <p className="text-xs text-muted-foreground">
                                Tip: turn on{' '}
                                <Link
                                    href={editSecurity()}
                                    className="underline underline-offset-2 hover:no-underline"
                                >
                                    two-factor authentication
                                </Link>{' '}
                                in Settings for extra security.
                            </p>
                        ) : null}

                        <ol className="space-y-4 text-sm">
                            <li className="rounded-md border border-border p-4">
                                <p className="font-medium">
                                    1. Download the signed addon
                                </p>
                                <p className="mt-1 text-muted-foreground">
                                    Upload the ZIP into{' '}
                                    <code className="text-xs">
                                        modules/addons
                                    </code>{' '}
                                    on your WHMCS server.
                                </p>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <Button asChild size="sm">
                                        <a href={addonDownloadUrl}>
                                            <Download
                                                className="size-4"
                                                aria-hidden
                                            />
                                            Download addon
                                        </a>
                                    </Button>
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={addonSetupUrl}>
                                            Full setup guide
                                        </Link>
                                    </Button>
                                </div>
                            </li>
                            <li className="rounded-md border border-border p-4">
                                <p className="font-medium">
                                    2. Create the installation and copy
                                    credentials
                                </p>
                                <p className="mt-1 text-muted-foreground">
                                    Credentials are shown once. Save them into a
                                    JSON file outside the WHMCS web root.
                                </p>
                                <Button
                                    asChild
                                    size="sm"
                                    variant="outline"
                                    className="mt-3"
                                >
                                    <Link href={integrationsUrl}>
                                        <Server
                                            className="size-4"
                                            aria-hidden
                                        />
                                        Open Installations
                                    </Link>
                                </Button>
                                {credentials ? (
                                    <div className="mt-3 rounded-md border border-warning/30 bg-warning-soft/30 p-3 text-xs">
                                        <p className="font-medium">
                                            Credentials ready (shown once)
                                        </p>
                                        <p className="mt-1 break-all">
                                            ID: {credentials.integration_id}
                                        </p>
                                    </div>
                                ) : null}
                            </li>
                            <li className="rounded-md border border-border p-4">
                                <p className="font-medium">
                                    3. Activate in WHMCS and point to the
                                    credentials file
                                </p>
                                <p className="mt-1 text-muted-foreground">
                                    Activate ResellerBytes AI Operations under
                                    Addon Modules, then enter only the absolute
                                    path to your credentials file.
                                </p>
                            </li>
                            <li className="rounded-md border border-border p-4">
                                <p className="font-medium">
                                    4. Confirm the WHMCS system cron is running
                                </p>
                                <p className="mt-1 text-muted-foreground">
                                    Make sure the normal WHMCS system cron runs
                                    crons/cron.php via PHP CLI about every 5
                                    minutes. This is how we know your WHMCS is
                                    online and how automatic checks run.
                                </p>
                            </li>
                        </ol>

                        <HeartbeatChecker
                            initial={heartbeat}
                            statusUrl={heartbeatStatusUrl}
                        />

                        {!heartbeat.connected ? (
                            <Form {...completeWhmcs.form()}>
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        {processing && <Spinner />}
                                        I finished the install steps
                                    </Button>
                                )}
                            </Form>
                        ) : null}
                    </div>
                )}

                {currentStep === 'first_scan' && (
                    <div className="flex flex-col gap-5">
                        {firstScan?.ready ? (
                            <>
                                <div className="rounded-md border border-border bg-card p-5">
                                    <h2 className="text-lg font-semibold">
                                        We found {firstScan.issues_count}{' '}
                                        {firstScan.issues_count === 1
                                            ? 'issue'
                                            : 'issues'}
                                    </h2>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {firstScan.one_click_count}{' '}
                                        {firstScan.one_click_count === 1
                                            ? 'can be fixed'
                                            : 'can be fixed'}{' '}
                                        in one click.
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-3">
                                    <Form {...completeFirstScan.form()}>
                                        {({ processing }) => (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="go_to_fix_center"
                                                    value="1"
                                                />
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    {processing && <Spinner />}
                                                    Open Fix Center
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                    <Form {...completeFirstScan.form()}>
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                {processing && <Spinner />}
                                                Go to Home
                                            </Button>
                                        )}
                                    </Form>
                                </div>
                            </>
                        ) : (
                            <FirstScanWaiter
                                statusUrl={heartbeatStatusUrl}
                                fixCenterUrl={fixCenterUrl}
                            />
                        )}
                    </div>
                )}
            </div>
        </>
    );
}
