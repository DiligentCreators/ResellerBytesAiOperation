import { Check, ChevronsUpDown } from 'lucide-react';
import {
    useEffect,
    useId,
    useMemo,
    useRef,
    useState,
    type KeyboardEvent,
} from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type CountryOption = {
    code: string;
    name: string;
};

type CountryComboboxProps = {
    id?: string;
    name?: string;
    countries: CountryOption[];
    defaultValue?: string | null;
    disabled?: boolean;
    required?: boolean;
};

/**
 * Searchable country picker. Submits the ISO country code via a hidden input.
 */
export function CountryCombobox({
    id = 'country',
    name = 'country',
    countries,
    defaultValue = 'US',
    disabled = false,
    required = false,
}: CountryComboboxProps) {
    const listboxId = useId();
    const containerRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const initial =
        countries.find((country) => country.code === (defaultValue ?? 'US')) ??
        countries[0] ??
        null;

    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState(initial?.name ?? '');
    const [value, setValue] = useState(initial?.code ?? '');
    const [activeIndex, setActiveIndex] = useState(0);

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();
        if (needle === '') {
            return countries;
        }

        return countries.filter(
            (country) =>
                country.name.toLowerCase().includes(needle) ||
                country.code.toLowerCase().includes(needle),
        );
    }, [countries, query]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (event: MouseEvent) => {
            if (
                containerRef.current &&
                !containerRef.current.contains(event.target as Node)
            ) {
                setOpen(false);
                const selected = countries.find(
                    (country) => country.code === value,
                );
                setQuery(selected?.name ?? '');
            }
        };

        document.addEventListener('mousedown', onPointerDown);
        return () => document.removeEventListener('mousedown', onPointerDown);
    }, [open, countries, value]);

    useEffect(() => {
        setActiveIndex(0);
    }, [query, open]);

    const selectCountry = (country: CountryOption) => {
        setValue(country.code);
        setQuery(country.name);
        setOpen(false);
        inputRef.current?.focus();
    };

    const openList = () => {
        if (disabled) {
            return;
        }

        setOpen(true);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (disabled) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            if (!open) {
                setOpen(true);
                return;
            }
            setActiveIndex((index) =>
                Math.min(index + 1, Math.max(filtered.length - 1, 0)),
            );
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            if (!open) {
                setOpen(true);
                return;
            }
            setActiveIndex((index) => Math.max(index - 1, 0));
            return;
        }

        if (event.key === 'Enter' && open) {
            event.preventDefault();
            const country = filtered[activeIndex];
            if (country) {
                selectCountry(country);
            }
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            setOpen(false);
            const selected = countries.find(
                (country) => country.code === value,
            );
            setQuery(selected?.name ?? '');
            return;
        }

        if (event.key === 'Tab') {
            setOpen(false);
        }
    };

    return (
        <div ref={containerRef} className="relative">
            <input
                type="hidden"
                name={name}
                value={value}
                required={required}
            />
            <div className="relative">
                <Input
                    ref={inputRef}
                    id={id}
                    role="combobox"
                    aria-label="Country"
                    aria-expanded={open}
                    aria-controls={listboxId}
                    aria-autocomplete="list"
                    aria-activedescendant={
                        open && filtered[activeIndex]
                            ? `${listboxId}-option-${filtered[activeIndex].code}`
                            : undefined
                    }
                    autoComplete="country"
                    disabled={disabled}
                    value={query}
                    data-testid="country-combobox"
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setOpen(true);
                    }}
                    onFocus={(event) => {
                        openList();
                        event.currentTarget.select();
                    }}
                    onClick={(event) => {
                        openList();
                        event.currentTarget.select();
                    }}
                    onKeyDown={onKeyDown}
                    className="pr-9"
                />
                <button
                    type="button"
                    tabIndex={-1}
                    disabled={disabled}
                    aria-label="Toggle country list"
                    className="absolute top-1/2 right-1.5 flex size-7 -translate-y-1/2 items-center justify-center rounded-md text-muted-foreground hover:text-foreground disabled:pointer-events-none"
                    onMouseDown={(event) => {
                        event.preventDefault();
                        if (disabled) {
                            return;
                        }
                        setOpen((current) => !current);
                        inputRef.current?.focus();
                    }}
                >
                    <ChevronsUpDown className="size-4" aria-hidden />
                </button>
            </div>
            {open && !disabled ? (
                <ul
                    id={listboxId}
                    role="listbox"
                    aria-label="Country"
                    data-testid="country-combobox-listbox"
                    className="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-md border border-border bg-popover p-1 text-sm shadow-md"
                >
                    {filtered.length === 0 ? (
                        <li
                            className="px-2 py-1.5 text-muted-foreground"
                            role="presentation"
                        >
                            No countries match
                        </li>
                    ) : (
                        filtered.map((country, index) => {
                            const selected = country.code === value;
                            const active = index === activeIndex;

                            return (
                                <li
                                    key={country.code}
                                    id={`${listboxId}-option-${country.code}`}
                                    role="option"
                                    aria-selected={selected}
                                    data-country-code={country.code}
                                    className={cn(
                                        'flex cursor-pointer items-center justify-between rounded-sm px-2 py-1.5',
                                        active && 'bg-muted',
                                        selected && 'font-medium',
                                    )}
                                    onMouseEnter={() => setActiveIndex(index)}
                                    onMouseDown={(event) => {
                                        event.preventDefault();
                                        selectCountry(country);
                                    }}
                                >
                                    <span>
                                        {country.name}{' '}
                                        <span className="text-muted-foreground">
                                            ({country.code})
                                        </span>
                                    </span>
                                    {selected ? (
                                        <Check
                                            className="size-4 text-brand"
                                            aria-hidden
                                        />
                                    ) : null}
                                </li>
                            );
                        })
                    )}
                </ul>
            ) : null}
        </div>
    );
}
