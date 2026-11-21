import { Form, Head, router } from '@inertiajs/react';
import { UserPlus, Users } from 'lucide-react';
import InputError from '@/components/input-error';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Pagination } from '@/components/pagination';

type Membership = {
    id: number;
    role: string;
    status: string;
    user?: { id: number; name: string; email: string } | null;
};

type Props = {
    tenant: { id: string; name: string };
    memberships: {
        data: Membership[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    roles: string[];
    status?: string;
};

export default function TenantMemberships({
    tenant,
    memberships,
    roles,
    status,
}: Props) {
    return (
        <>
            <Head title="Team members" />
            <div className="space-y-6">
                <PageHeader
                    title="Team members"
                    description={`Invite and manage access for ${tenant.name}.`}
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
                            Invite a member
                        </h2>
                    </div>
                    <Form
                        action={`/tenants/${tenant.id}/memberships`}
                        method="post"
                        className="grid gap-4 sm:grid-cols-[1fr_160px_auto]"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        required
                                    />
                                    <InputError message={errors.email} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="role">Role</Label>
                                    <select
                                        id="role"
                                        name="role"
                                        defaultValue="viewer"
                                        className="flex h-9 w-full rounded-md border border-input bg-white px-3 text-sm shadow-xs"
                                    >
                                        {roles.map((role) => (
                                            <option key={role} value={role}>
                                                {role}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.role} />
                                </div>
                                <div className="flex items-end">
                                    <Button type="submit" disabled={processing}>
                                        Send invite
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>

                {memberships.data.length === 0 ? (
                    <EmptyState
                        icon={Users}
                        title="No members yet"
                        description="Invite a teammate to collaborate in this workspace."
                    />
                ) : (
                    <div className="overflow-hidden rounded-lg border border-border bg-card shadow-xs">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">
                                Workspace members
                            </caption>
                            <thead className="border-b border-border bg-surface text-xs font-medium text-muted-foreground">
                                <tr>
                                    <th scope="col" className="h-9 px-4">
                                        Member
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        Role
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        Status
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {memberships.data.map((membership) => (
                                    <tr
                                        key={membership.id}
                                        className="h-11 border-b border-border last:border-0 hover:bg-surface/70"
                                    >
                                        <td className="px-4">
                                            <div className="font-medium">
                                                {membership.user?.name ??
                                                    'Unknown'}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {membership.user?.email}
                                            </div>
                                        </td>
                                        <td className="px-4 capitalize">
                                            {membership.role}
                                        </td>
                                        <td className="px-4">
                                            <StatusBadge
                                                status={membership.status}
                                            />
                                        </td>
                                        <td className="px-4">
                                            <div className="flex flex-wrap gap-2">
                                                {membership.role !==
                                                    'owner' && (
                                                    <>
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="secondary"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/tenants/${tenant.id}/memberships/${membership.id}/suspend`,
                                                                )
                                                            }
                                                        >
                                                            Suspend
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="destructive"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/tenants/${tenant.id}/memberships/${membership.id}/revoke`,
                                                                )
                                                            }
                                                        >
                                                            Revoke
                                                        </Button>
                                                    </>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <Pagination links={memberships.links} />
                    </div>
                )}
            </div>
        </>
    );
}
