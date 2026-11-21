import * as React from 'react';

import { cn } from '@/lib/utils';

function Input({ className, type, ...props }: React.ComponentProps<'input'>) {
    return (
        <input
            type={type}
            data-slot="input"
            className={cn(
                'flex h-9 w-full min-w-0 rounded-md border border-input bg-white px-3 text-sm shadow-xs transition-colors duration-150 outline-none motion-reduce:transition-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-slate-brand disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-card',
                'focus-visible:border-brand focus-visible:ring-[3px] focus-visible:ring-brand/25',
                'aria-invalid:border-danger aria-invalid:ring-danger/20',
                className,
            )}
            {...props}
        />
    );
}

export { Input };
