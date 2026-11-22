import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type SettingSource = 'database' | 'env' | 'default';

type SettingField = {
    key: string;
    label: string;
    help: string;
    type:
        | 'bool'
        | 'int'
        | 'float'
        | 'string'
        | 'email'
        | 'url'
        | 'json'
        | 'secret';
    source: SettingSource;
    value: string | number | boolean | null;
    is_set: boolean;
    min?: number | null;
    max?: number | null;
};

type Group = {
    id: string;
    label: string;
};

type Props = {
    groups: Group[];
    settings: Record<string, SettingField[]>;
    activeGroup?: string;
    status?: string;
};

const checkboxLabelClassName =
    'flex items-center gap-2 text-sm text-muted-foreground';

function sourceBadgeVariant(
    source: SettingSource,
): 'info' | 'secondary' | 'outline' {
    if (source === 'database') {
        return 'info';
    }
    if (source === 'env') {
        return 'secondary';
    }

    return 'outline';
}

function sourceLabel(source: SettingSource): string {
    if (source === 'database') {
        return 'Database';
    }
    if (source === 'env') {
        return '.env fallback';
    }

    return 'Default';
}

function fieldError(
    errors: Record<string, string> | undefined,
    key: string,
): string | undefined {
    if (!errors) {
        return undefined;
    }

    return errors[`settings.${key}`] ?? errors[key];
}

function buildValues(
    settingsMap: Record<string, SettingField[]>,
): Record<string, string | boolean> {
    return Object.fromEntries(
        Object.values(settingsMap)
            .flat()
            .map((field) => {
                if (field.type === 'secret') {
                    return [field.key, ''];
                }
                if (field.type === 'bool') {
                    return [field.key, Boolean(field.value)];
                }
                if (field.value === null || field.value === undefined) {
                    return [field.key, ''];
                }

                return [field.key, String(field.value)];
            }),
    );
}

export default function PlatformSettingsPage({
    groups,
    settings,
    activeGroup,
    status,
}: Props) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const initialGroup =
        groups.find((group) => group.id === activeGroup)?.id ??
        groups[0]?.id ??
        'security';
    const [group, setGroup] = useState(initialGroup);
    const fields = settings[group] ?? [];

    const [values, setValues] = useState<Record<string, string | boolean>>(() =>
        buildValues(settings),
    );

    useEffect(() => {
        setValues(buildValues(settings));
        if (activeGroup) {
            setGroup(activeGroup);
        }
    }, [settings, activeGroup]);

    const [processing, setProcessing] = useState(false);
    const [resettingKey, setResettingKey] = useState<string | null>(null);

    const groupFields = useMemo(
        () => fields.map((field) => field.key),
        [fields],
    );

    function updateField(key: string, value: string | boolean): void {
        setValues((current) => ({ ...current, [key]: value }));
    }

    function submitGroup(event: React.FormEvent<HTMLFormElement>): void {
        event.preventDefault();

        const payload: Record<string, string | boolean | number | null> = {};
        for (const key of groupFields) {
            const field = fields.find((item) => item.key === key);
            if (!field) {
                continue;
            }

            const raw = values[key];
            if (field.type === 'secret') {
                payload[key] = typeof raw === 'string' ? raw : '';
                continue;
            }
            if (field.type === 'bool') {
                payload[key] = Boolean(raw);
                continue;
            }
            if (field.type === 'int' || field.type === 'float') {
                payload[key] =
                    raw === '' || raw === undefined ? null : Number(raw);
                continue;
            }
            payload[key] = typeof raw === 'string' ? raw : String(raw ?? '');
        }

        setProcessing(true);
        router.put(
            `/platform/settings/${group}`,
            { settings: payload },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
            },
        );
    }

    function resetKey(key: string): void {
        setResettingKey(key);
        router.delete(`/platform/settings/key/${encodeURIComponent(key)}`, {
            preserveScroll: true,
            onFinish: () => setResettingKey(null),
        });
    }

    return (
        <>
            <Head title="Platform settings" />
            <div className="space-y-6">
                <PageHeader
                    title="Platform settings"
                    description="Operational defaults stored in the database with .env fallback. Infrastructure secrets stay in the environment only."
                />

                {status ? (
                    <p
                        role="status"
                        className="rounded-md border border-success/20 bg-success-soft px-3 py-2 text-sm text-success-fg"
                    >
                        {status}
                    </p>
                ) : null}

                <div className="flex flex-wrap gap-2 border-b border-border pb-3">
                    {groups.map((item) => (
                        <button
                            key={item.id}
                            type="button"
                            onClick={() => setGroup(item.id)}
                            className={cn(
                                'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                group === item.id
                                    ? 'bg-brand/15 text-foreground'
                                    : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                            )}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>

                <form onSubmit={submitGroup} className="space-y-4">
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <div className="space-y-6">
                            {fields.map((field) => (
                                <div
                                    key={field.key}
                                    className="grid gap-3 border-b border-border/70 pb-5 last:border-b-0 last:pb-0 md:grid-cols-[minmax(0,1fr)_auto] md:items-start"
                                >
                                    <div className="space-y-3">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Label htmlFor={field.key}>
                                                {field.label}
                                            </Label>
                                            <Badge
                                                variant={sourceBadgeVariant(
                                                    field.source,
                                                )}
                                            >
                                                {sourceLabel(field.source)}
                                            </Badge>
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            {field.help}
                                        </p>

                                        {field.type === 'bool' ? (
                                            <label
                                                className={
                                                    checkboxLabelClassName
                                                }
                                            >
                                                <input
                                                    id={field.key}
                                                    type="checkbox"
                                                    checked={Boolean(
                                                        values[field.key],
                                                    )}
                                                    onChange={(event) =>
                                                        updateField(
                                                            field.key,
                                                            event.target
                                                                .checked,
                                                        )
                                                    }
                                                    className="size-4 rounded border-input"
                                                />
                                                Enabled
                                            </label>
                                        ) : null}

                                        {field.type === 'secret' ? (
                                            <div className="grid gap-2">
                                                <p className="text-sm">
                                                    {field.is_set
                                                        ? 'Set'
                                                        : 'Not set'}
                                                </p>
                                                <Input
                                                    id={field.key}
                                                    type="password"
                                                    autoComplete="off"
                                                    placeholder="Replace secret…"
                                                    value={String(
                                                        values[field.key] ?? '',
                                                    )}
                                                    onChange={(event) =>
                                                        updateField(
                                                            field.key,
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                        ) : null}

                                        {field.type !== 'bool' &&
                                        field.type !== 'secret' ? (
                                            <Input
                                                id={field.key}
                                                type={
                                                    field.type === 'int' ||
                                                    field.type === 'float'
                                                        ? 'number'
                                                        : field.type === 'email'
                                                          ? 'email'
                                                          : field.type === 'url'
                                                            ? 'url'
                                                            : 'text'
                                                }
                                                min={field.min ?? undefined}
                                                max={field.max ?? undefined}
                                                step={
                                                    field.type === 'float'
                                                        ? 'any'
                                                        : undefined
                                                }
                                                value={String(
                                                    values[field.key] ?? '',
                                                )}
                                                onChange={(event) =>
                                                    updateField(
                                                        field.key,
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        ) : null}

                                        <InputError
                                            message={fieldError(
                                                errors,
                                                field.key,
                                            )}
                                        />
                                    </div>

                                    <div className="flex justify-end">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            disabled={
                                                field.source !== 'database' ||
                                                resettingKey === field.key
                                            }
                                            onClick={() => resetKey(field.key)}
                                        >
                                            {resettingKey === field.key
                                                ? 'Resetting…'
                                                : 'Reset to fallback'}
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </section>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving…' : 'Save group'}
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}
