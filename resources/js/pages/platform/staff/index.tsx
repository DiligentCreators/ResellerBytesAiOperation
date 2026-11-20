import { Form, Head } from '@inertiajs/react';
import { Shield, UserPlus, Users } from 'lucide-react';

import { EmptyState } from '@/components/empty-state';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Staff = {
    id: number;
    user_id: number;
    name: string;
    email: string;
    permissions: string[];
};

type Props = {
    permissions: string[];
    staff: Staff[];
    status?: string;
};

const permissionHelp: Record<string, string> = {
    tenants: 'Tenant administration',
    billing: 'Billing operations',
    support: 'Support tools',
    settings: 'Platform configuration',
    audit: 'Audit evidence access',
};

function PermissionChoices({
    permissions,
    selected = [],
}: {
    permissions: string[];
    selected?: string[];
}) {
    return (
        <div className="grid gap-2 sm:grid-cols-2">
            {permissions.map((permission) => (
                <label
                    key={permission}
                    className="flex items-start gap-3 rounded-lg border border-border bg-surface/50 p-3 text-sm"
                >
                    <input
                        type="checkbox"
                        name="permissions[]"
                        value={permission}
                        defaultChecked={selected.includes(permission)}
                        className="mt-1"
                    />
                    <span>
                        <span className="font-medium capitalize">
                            {permission}
                        </span>
                        <span className="mt-0.5 block text-xs text-muted-foreground">
                            {permissionHelp[permission] ?? permission}
                        </span>
                    </span>
                </label>
            ))}
        </div>
    );
}

export default function CentralStaffPage({
    permissions,
    staff,
    status,
}: Props) {
    return (
        <>
            <Head title="Central staff access" />
            <div className="space-y-6">
                <PageHeader
                    title="Central staff"
                    description="Create operator accounts with least-privilege permissions. The configured platform owner always keeps full access."
                />

                {status ? (
                    <p
                        role="status"
                        className="rounded-md border border-success/20 bg-success-soft px-3 py-2 text-sm text-success-fg"
                    >
                        {status}
                    </p>
                ) : null}

                <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                    <div className="mb-4 flex items-center gap-2">
                        <UserPlus
                            className="size-4 text-slate-brand"
                            aria-hidden
                        />
                        <h2 className="text-sm font-semibold">
                            Create staff account
                        </h2>
                    </div>
                    <Form
                        action="/platform/staff"
                        method="post"
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="staff-name">
                                            Full name
                                        </Label>
                                        <Input
                                            id="staff-name"
                                            name="name"
                                            required
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="staff-email">
                                            Email
                                        </Label>
                                        <Input
                                            id="staff-email"
                                            name="email"
                                            type="email"
                                            required
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="staff-password">
                                        Temporary password
                                    </Label>
                                    <Input
                                        id="staff-password"
                                        name="password"
                                        type="password"
                                        autoComplete="new-password"
                                        required
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        At least 14 characters with mixed case,
                                        a number, and a symbol. Deliver through
                                        a secure channel.
                                    </p>
                                    <InputError message={errors.password} />
                                </div>
                                <div className="space-y-2">
                                    <Label>Permissions</Label>
                                    <PermissionChoices
                                        permissions={permissions}
                                    />
                                    <InputError message={errors.permissions} />
                                </div>
                                <Button disabled={processing}>
                                    Create staff account
                                </Button>
                            </>
                        )}
                    </Form>
                </section>

                <section className="space-y-4">
                    <div className="flex items-center gap-2">
                        <Users
                            className="size-4 text-slate-brand"
                            aria-hidden
                        />
                        <h2 className="text-sm font-semibold">
                            Existing staff
                        </h2>
                    </div>
                    {staff.length === 0 ? (
                        <EmptyState
                            icon={Shield}
                            title="No staff accounts yet"
                            description="Create the first operator account above."
                        />
                    ) : null}
                    {staff.map((person) => (
                        <div
                            key={person.id}
                            className="space-y-4 rounded-lg border border-border bg-card p-5 shadow-xs"
                        >
                            <div>
                                <h3 className="font-medium">{person.name}</h3>
                                <p className="text-sm text-muted-foreground">
                                    {person.email}
                                </p>
                            </div>
                            <Form
                                action={`/platform/staff/${person.id}`}
                                method="put"
                                className="space-y-3"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <PermissionChoices
                                            permissions={permissions}
                                            selected={person.permissions}
                                        />
                                        <InputError
                                            message={errors.permissions}
                                        />
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                variant="secondary"
                                                disabled={processing}
                                            >
                                                Save permissions
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                            <Form
                                action={`/platform/staff/${person.id}`}
                                method="delete"
                            >
                                {({ processing }) => (
                                    <Button
                                        variant="destructive"
                                        disabled={processing}
                                        onClick={(event) => {
                                            if (
                                                !confirm(
                                                    `Delete staff account for ${person.email}?`,
                                                )
                                            ) {
                                                event.preventDefault();
                                            }
                                        }}
                                    >
                                        Delete staff account
                                    </Button>
                                )}
                            </Form>
                        </div>
                    ))}
                </section>
            </div>
        </>
    );
}
