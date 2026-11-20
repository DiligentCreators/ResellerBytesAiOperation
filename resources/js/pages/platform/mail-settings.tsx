import { Form, Head } from '@inertiajs/react';
import { Mail, Send } from 'lucide-react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    driver: 'log' | 'smtp' | 'postmark';
    from_address: string;
    from_name: string;
    smtp_host: string;
    smtp_port: number;
    smtp_encryption: 'tls' | 'ssl';
    smtp_username_set: boolean;
    smtp_password_set: boolean;
    postmark_token_set: boolean;
    postmark_message_stream: string;
    status?: string;
};

const selectClassName =
    'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm';
const checkboxLabelClassName =
    'flex items-center gap-2 text-sm text-muted-foreground';

export default function MailSettings(props: Props) {
    return (
        <>
            <Head title="Platform mail settings" />
            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Outbound email
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Central mail transport for platform alerts, invitations,
                        and verification messages. Secrets stay encrypted and
                        are never shown again after save.
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
                    action="/platform/mail-settings"
                    method="put"
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <section className="rounded-xl border bg-card p-5">
                                <div className="flex items-center gap-2">
                                    <Mail className="size-5" />
                                    <h2 className="font-semibold">Transport</h2>
                                </div>
                                <div className="mt-4 grid gap-4 md:grid-cols-2">
                                    <div className="grid gap-2 md:col-span-2">
                                        <Label htmlFor="driver">Driver</Label>
                                        <select
                                            id="driver"
                                            name="driver"
                                            defaultValue={props.driver}
                                            className={selectClassName}
                                        >
                                            <option value="log">
                                                Log only (development)
                                            </option>
                                            <option value="smtp">SMTP</option>
                                            <option value="postmark">
                                                Postmark
                                            </option>
                                        </select>
                                        <InputError message={errors.driver} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="from_address">
                                            From address
                                        </Label>
                                        <Input
                                            id="from_address"
                                            name="from_address"
                                            type="email"
                                            defaultValue={props.from_address}
                                        />
                                        <InputError
                                            message={errors.from_address}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="from_name">
                                            From name
                                        </Label>
                                        <Input
                                            id="from_name"
                                            name="from_name"
                                            defaultValue={props.from_name}
                                        />
                                        <InputError
                                            message={errors.from_name}
                                        />
                                    </div>
                                </div>
                            </section>

                            <section className="rounded-xl border bg-card p-5">
                                <h2 className="font-semibold">SMTP</h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Used when the driver is SMTP.
                                </p>
                                <div className="mt-4 grid gap-4 md:grid-cols-2">
                                    <div className="grid gap-2 md:col-span-2">
                                        <Label htmlFor="smtp_host">Host</Label>
                                        <Input
                                            id="smtp_host"
                                            name="smtp_host"
                                            defaultValue={props.smtp_host}
                                        />
                                        <InputError
                                            message={errors.smtp_host}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="smtp_port">Port</Label>
                                        <Input
                                            id="smtp_port"
                                            name="smtp_port"
                                            type="number"
                                            defaultValue={props.smtp_port}
                                        />
                                        <InputError
                                            message={errors.smtp_port}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="smtp_encryption">
                                            Encryption
                                        </Label>
                                        <select
                                            id="smtp_encryption"
                                            name="smtp_encryption"
                                            defaultValue={props.smtp_encryption}
                                            className={selectClassName}
                                        >
                                            <option value="tls">
                                                STARTTLS (587)
                                            </option>
                                            <option value="ssl">
                                                Implicit TLS (465)
                                            </option>
                                        </select>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="smtp_username">
                                            Username
                                            {props.smtp_username_set
                                                ? ' (saved)'
                                                : ''}
                                        </Label>
                                        <Input
                                            id="smtp_username"
                                            name="smtp_username"
                                            type="password"
                                            autoComplete="new-password"
                                            placeholder={
                                                props.smtp_username_set
                                                    ? '••••••••••••'
                                                    : 'SMTP username'
                                            }
                                        />
                                        {props.smtp_username_set && (
                                            <label
                                                className={
                                                    checkboxLabelClassName
                                                }
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="clear_smtp_username"
                                                    value="1"
                                                />
                                                Clear saved username
                                            </label>
                                        )}
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="smtp_password">
                                            Password
                                            {props.smtp_password_set
                                                ? ' (saved)'
                                                : ''}
                                        </Label>
                                        <Input
                                            id="smtp_password"
                                            name="smtp_password"
                                            type="password"
                                            autoComplete="new-password"
                                            placeholder={
                                                props.smtp_password_set
                                                    ? '••••••••••••'
                                                    : 'SMTP password'
                                            }
                                        />
                                        <InputError
                                            message={errors.smtp_password}
                                        />
                                        {props.smtp_password_set && (
                                            <label
                                                className={
                                                    checkboxLabelClassName
                                                }
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="clear_smtp_password"
                                                    value="1"
                                                />
                                                Clear saved password
                                            </label>
                                        )}
                                    </div>
                                </div>
                            </section>

                            <section className="rounded-xl border bg-card p-5">
                                <h2 className="font-semibold">Postmark</h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Used when the driver is Postmark.
                                </p>
                                <div className="mt-4 grid gap-4 md:grid-cols-2">
                                    <div className="grid gap-2 md:col-span-2">
                                        <Label htmlFor="postmark_server_token">
                                            Server token
                                            {props.postmark_token_set
                                                ? ' (saved)'
                                                : ''}
                                        </Label>
                                        <Input
                                            id="postmark_server_token"
                                            name="postmark_server_token"
                                            type="password"
                                            autoComplete="new-password"
                                            placeholder={
                                                props.postmark_token_set
                                                    ? '••••••••••••'
                                                    : 'Server token'
                                            }
                                        />
                                        <InputError
                                            message={
                                                errors.postmark_server_token
                                            }
                                        />
                                        {props.postmark_token_set && (
                                            <label
                                                className={
                                                    checkboxLabelClassName
                                                }
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="clear_postmark_token"
                                                    value="1"
                                                />
                                                Clear saved token
                                            </label>
                                        )}
                                    </div>
                                    <div className="grid gap-2 md:col-span-2">
                                        <Label htmlFor="postmark_message_stream">
                                            Message stream
                                        </Label>
                                        <Input
                                            id="postmark_message_stream"
                                            name="postmark_message_stream"
                                            defaultValue={
                                                props.postmark_message_stream
                                            }
                                        />
                                    </div>
                                </div>
                            </section>

                            <Button disabled={processing}>
                                Save mail settings
                            </Button>
                        </>
                    )}
                </Form>

                <section className="rounded-xl border bg-card p-5">
                    <div className="flex items-center gap-2">
                        <Send className="size-5" />
                        <h2 className="font-semibold">Send a test</h2>
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Uses the currently saved transport.
                    </p>
                    <Form
                        action="/platform/mail-settings/test"
                        method="post"
                        className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid flex-1 gap-2">
                                    <Label htmlFor="recipient">
                                        Recipient email
                                    </Label>
                                    <Input
                                        id="recipient"
                                        name="recipient"
                                        type="email"
                                        required
                                    />
                                    <InputError message={errors.recipient} />
                                </div>
                                <Button
                                    variant="secondary"
                                    disabled={processing}
                                >
                                    Send test email
                                </Button>
                            </>
                        )}
                    </Form>
                </section>
            </div>
        </>
    );
}
