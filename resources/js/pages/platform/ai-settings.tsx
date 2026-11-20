import { Form, Head } from '@inertiajs/react';
import { Bot, KeyRound, Sparkles } from 'lucide-react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    api_key_set: boolean;
    model: string;
    reasoning_effort: string;
    timeout: number;
    allowed_models: string[];
    reasoning_efforts: string[];
    signing_configured: boolean;
    signing_public_key: string;
    status?: string;
};

const selectClassName =
    'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm';

export default function AiSettings(props: Props) {
    return (
        <>
            <Head title="AI & addon settings" />
            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        AI & addon signing
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Configure OpenAI for diagnostic analysis and the Ed25519
                        key pair used to sign customer WHMCS addon downloads.
                        Values are stored encrypted — no need to edit `.env`
                        for routine changes.
                    </p>
                </div>

                {props.status && (
                    <p
                        role="status"
                        className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300"
                    >
                        {props.status}
                    </p>
                )}

                <Form
                    action="/platform/ai-settings"
                    method="put"
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <section className="rounded-xl border bg-card p-5">
                                <div className="flex items-center gap-2">
                                    <Sparkles className="size-5" />
                                    <h2 className="font-semibold">
                                        OpenAI analysis
                                    </h2>
                                </div>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Customers never see this key. Analysis uses
                                    the selected model for WHMCS diagnostic
                                    snapshots.
                                </p>
                                <div className="mt-4 grid gap-4 md:grid-cols-2">
                                    <div className="grid gap-2 md:col-span-2">
                                        <Label htmlFor="api_key">
                                            API key
                                            {props.api_key_set
                                                ? ' (saved)'
                                                : ''}
                                        </Label>
                                        <Input
                                            id="api_key"
                                            name="api_key"
                                            type="password"
                                            autoComplete="new-password"
                                            placeholder={
                                                props.api_key_set
                                                    ? '••••••••••••'
                                                    : 'sk-...'
                                            }
                                        />
                                        <InputError message={errors.api_key} />
                                        {props.api_key_set && (
                                            <label className="flex items-center gap-2 text-sm text-muted-foreground">
                                                <input
                                                    type="checkbox"
                                                    name="clear_api_key"
                                                    value="1"
                                                />
                                                Clear saved API key
                                            </label>
                                        )}
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="model">Model</Label>
                                        <select
                                            id="model"
                                            name="model"
                                            defaultValue={props.model}
                                            className={selectClassName}
                                        >
                                            {props.allowed_models.map(
                                                (model) => (
                                                    <option
                                                        key={model}
                                                        value={model}
                                                    >
                                                        {model}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                        <InputError message={errors.model} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="reasoning_effort">
                                            Reasoning effort
                                        </Label>
                                        <select
                                            id="reasoning_effort"
                                            name="reasoning_effort"
                                            defaultValue={
                                                props.reasoning_effort
                                            }
                                            className={selectClassName}
                                        >
                                            {props.reasoning_efforts.map(
                                                (effort) => (
                                                    <option
                                                        key={effort}
                                                        value={effort}
                                                    >
                                                        {effort}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                        <InputError
                                            message={errors.reasoning_effort}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="timeout">
                                            Timeout (seconds)
                                        </Label>
                                        <Input
                                            id="timeout"
                                            name="timeout"
                                            type="number"
                                            min={5}
                                            max={300}
                                            defaultValue={props.timeout}
                                        />
                                        <InputError message={errors.timeout} />
                                    </div>
                                </div>
                            </section>

                            <section className="rounded-xl border bg-card p-5">
                                <div className="flex items-center gap-2">
                                    <KeyRound className="size-5" />
                                    <h2 className="font-semibold">
                                        WHMCS addon signing
                                    </h2>
                                </div>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Customers download a signed ZIP from Setup.
                                    Generate a key pair here, or paste an
                                    existing base64 Ed25519 pair.
                                </p>
                                <div className="mt-3 rounded-lg border bg-muted/40 px-3 py-2 text-sm">
                                    Status:{' '}
                                    <span className="font-medium">
                                        {props.signing_configured
                                            ? 'Configured'
                                            : 'Not configured'}
                                    </span>
                                </div>
                                <div className="mt-4 grid gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="signing_secret_key">
                                            Secret key (base64)
                                        </Label>
                                        <Input
                                            id="signing_secret_key"
                                            name="signing_secret_key"
                                            type="password"
                                            autoComplete="new-password"
                                            placeholder={
                                                props.signing_configured
                                                    ? '••••••••••••'
                                                    : 'Paste or generate'
                                            }
                                        />
                                        <InputError
                                            message={errors.signing_secret_key}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="signing_public_key">
                                            Public key (base64)
                                        </Label>
                                        <Input
                                            id="signing_public_key"
                                            name="signing_public_key"
                                            defaultValue={
                                                props.signing_configured
                                                    ? ''
                                                    : props.signing_public_key
                                            }
                                            placeholder={
                                                props.signing_configured
                                                    ? 'Leave blank to keep saved public key'
                                                    : 'Paste public key'
                                            }
                                        />
                                        <InputError
                                            message={errors.signing_public_key}
                                        />
                                    </div>
                                    {props.signing_configured && (
                                        <label className="flex items-center gap-2 text-sm text-muted-foreground">
                                            <input
                                                type="checkbox"
                                                name="clear_signing_keys"
                                                value="1"
                                            />
                                            Clear saved signing keys
                                        </label>
                                    )}
                                </div>
                            </section>

                            <Button disabled={processing}>
                                Save AI & signing settings
                            </Button>
                        </>
                    )}
                </Form>

                <section className="rounded-xl border bg-card p-5">
                    <div className="flex items-center gap-2">
                        <Bot className="size-5" />
                        <h2 className="font-semibold">
                            Generate signing keys
                        </h2>
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Creates a fresh Ed25519 pair and saves it encrypted.
                        Existing customer packages signed with an old key will
                        need re-download after rotation.
                    </p>
                    <Form
                        action="/platform/ai-settings/generate-signing-keys"
                        method="post"
                        className="mt-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <InputError
                                    message={errors.signing_secret_key}
                                />
                                <Button
                                    variant="secondary"
                                    disabled={processing}
                                    onClick={(event) => {
                                        if (
                                            props.signing_configured &&
                                            !confirm(
                                                'Replace the current signing key pair?',
                                            )
                                        ) {
                                            event.preventDefault();
                                        }
                                    }}
                                >
                                    Generate Ed25519 key pair
                                </Button>
                            </>
                        )}
                    </Form>
                </section>
            </div>
        </>
    );
}
