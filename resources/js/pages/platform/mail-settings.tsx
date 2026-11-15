import { Form, Head } from '@inertiajs/react';
import Heading from '@/components/heading';
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

const selectClassName = 'rounded-md border bg-background p-2';

export default function MailSettings(props: Props) {
    return (
        <>
            <Head title="Platform mail settings" />
            <div className="space-y-6">
                <Heading
                    title="Platform outbound email"
                    description={
                        'One central mail configuration for platform ' +
                        'and tenant system messages.'
                    }
                />
                {props.status && (
                    <p role="status" className="text-sm text-green-700">
                        {props.status}
                    </p>
                )}
                <Form
                    action="/platform/mail-settings"
                    method="put"
                    className="space-y-5"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="driver">Email driver</Label>
                                <select
                                    id="driver"
                                    name="driver"
                                    defaultValue={props.driver}
                                    className={selectClassName}
                                >
                                    <option value="log">
                                        Log only (no email sent)
                                    </option>
                                    <option value="smtp">SMTP</option>
                                    <option value="postmark">Postmark</option>
                                </select>
                                <InputError message={errors.driver} />
                            </div>

                            <p className="text-sm text-muted-foreground">
                                SMTP fields
                            </p>
                            <div className="grid gap-2">
                                <Label htmlFor="smtp_host">SMTP host</Label>
                                <Input
                                    id="smtp_host"
                                    name="smtp_host"
                                    defaultValue={props.smtp_host}
                                />
                                <InputError message={errors.smtp_host} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="smtp_port">SMTP port</Label>
                                <Input
                                    id="smtp_port"
                                    name="smtp_port"
                                    type="number"
                                    defaultValue={props.smtp_port}
                                />
                                <InputError message={errors.smtp_port} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="smtp_encryption">
                                    SMTP encryption
                                </Label>
                                <select
                                    id="smtp_encryption"
                                    name="smtp_encryption"
                                    defaultValue={props.smtp_encryption}
                                    className="rounded-md border bg-background p-2"
                                >
                                    <option value="tls">STARTTLS</option>
                                    <option value="ssl">Implicit TLS</option>
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="smtp_username">
                                    SMTP username
                                    {props.smtp_username_set
                                        ? ' (saved; leave blank to keep)'
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
                                            : 'Enter SMTP username'
                                    }
                                />
                                {props.smtp_username_set && (
                                    <label
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            name="clear_smtp_username"
                                            value="1"
                                        />
                                        Clear saved SMTP username
                                    </label>
                                )}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="smtp_password">
                                    SMTP password
                                    {props.smtp_password_set
                                        ? ' (saved; leave blank to keep)'
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
                                            : 'Enter SMTP password'
                                    }
                                />
                                <InputError message={errors.smtp_password} />
                                {props.smtp_password_set && (
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="clear_smtp_password"
                                            value="1"
                                        />
                                        Clear saved SMTP password
                                    </label>
                                )}
                            </div>

                            <p className="text-sm text-muted-foreground">
                                Postmark fields
                            </p>
                            <div className="grid gap-2">
                                <Label htmlFor="postmark_server_token">
                                    Postmark server token
                                    {props.postmark_token_set
                                        ? ' (saved; leave blank to keep)'
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
                                            : 'Enter server token'
                                    }
                                />
                                <InputError
                                    message={errors.postmark_server_token}
                                />
                                {props.postmark_token_set && (
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="clear_postmark_token"
                                            value="1"
                                        />
                                        Clear saved Postmark token
                                    </label>
                                )}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="postmark_message_stream">
                                    Postmark message stream
                                </Label>
                                <Input
                                    id="postmark_message_stream"
                                    name="postmark_message_stream"
                                    defaultValue={props.postmark_message_stream}
                                />
                            </div>

                            <p className="text-sm text-muted-foreground">
                                Sender identity (required for SMTP/Postmark)
                            </p>
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
                                <InputError message={errors.from_address} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="from_name">From name</Label>
                                <Input
                                    id="from_name"
                                    name="from_name"
                                    defaultValue={props.from_name}
                                />
                                <InputError message={errors.from_name} />
                            </div>
                            <Button disabled={processing}>
                                Save mail settings
                            </Button>
                        </>
                    )}
                </Form>

                <Form
                    action="/platform/mail-settings/test"
                    method="post"
                    className="flex items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid flex-1 gap-2">
                                <Label htmlFor="recipient">
                                    Send test email to
                                </Label>
                                <Input
                                    id="recipient"
                                    name="recipient"
                                    type="email"
                                    required
                                />
                                <InputError message={errors.recipient} />
                            </div>
                            <Button variant="secondary" disabled={processing}>
                                Send test email
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
