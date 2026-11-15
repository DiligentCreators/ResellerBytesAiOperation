import { Form, Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
                    className="flex items-center gap-2 text-sm capitalize"
                >
                    <input
                        type="checkbox"
                        name="permissions[]"
                        value={permission}
                        defaultChecked={selected.includes(permission)}
                    />
                    {permission}
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
            <div className="space-y-8">
                <Heading
                    title="Central staff access"
                    description={
                        'Create separate central admin accounts and grant ' +
                        'least-privilege feature permissions. The configured ' +
                        'super admin always retains full access.'
                    }
                />
                {status && (
                    <p role="status" className="text-sm text-green-700">
                        {status}
                    </p>
                )}

                <section className="space-y-4 rounded-lg border p-5">
                    <h2 className="font-semibold">Create staff account</h2>
                    <Form
                        action="/platform/staff"
                        method="post"
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
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
                                    <Label htmlFor="staff-email">Email</Label>
                                    <Input
                                        id="staff-email"
                                        name="email"
                                        type="email"
                                        required
                                    />
                                    <InputError message={errors.email} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="staff-password">
                                        {'Temporary password (14+ chars; ' +
                                            'mixed case, number and symbol)'}
                                    </Label>
                                    <Input
                                        id="staff-password"
                                        name="password"
                                        type="password"
                                        autoComplete="new-password"
                                        required
                                    />
                                    <InputError message={errors.password} />
                                </div>
                                <div className="space-y-2">
                                    <Label>Permissions</Label>
                                    <PermissionChoices
                                        permissions={permissions}
                                    />
                                    <InputError
                                        message={errors.permissions}
                                    />
                                </div>
                                <Button disabled={processing}>
                                    Create staff account
                                </Button>
                            </>
                        )}
                    </Form>
                </section>

                <section className="space-y-4">
                    <h2 className="font-semibold">Existing staff</h2>
                    {staff.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No staff accounts have been created.
                        </p>
                    )}
                    {staff.map((person) => (
                        <div
                            key={person.id}
                            className="space-y-4 rounded-lg border p-5"
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
                                        <Button
                                            variant="secondary"
                                            disabled={processing}
                                        >
                                            Save permissions
                                        </Button>
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
