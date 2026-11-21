import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

const badgeVariants = cva(
    'inline-flex h-5 w-fit shrink-0 items-center justify-center gap-1 overflow-hidden rounded-md px-1.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset transition-[color,box-shadow] duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background [&>svg]:pointer-events-none [&>svg]:size-3',
    {
        variants: {
            variant: {
                default:
                    'border-transparent bg-primary text-primary-foreground ring-transparent [a&]:hover:bg-primary-hover',
                secondary:
                    'border-transparent bg-muted text-[#334155] ring-border dark:text-foreground',
                destructive:
                    'border-transparent bg-danger-soft text-danger-fg ring-danger/20',
                outline: 'text-foreground ring-border [a&]:hover:bg-muted',
                success:
                    'bg-success-soft text-success-fg ring-success/20',
                warning:
                    'bg-warning-soft text-warning-fg ring-warning/25',
                info: 'bg-info-soft text-info-fg ring-brand/25',
                popular:
                    'h-6 rounded-full bg-brand px-2.5 text-xs font-semibold text-[#0B1220] ring-transparent',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

function Badge({
    className,
    variant,
    asChild = false,
    ...props
}: React.ComponentProps<'span'> &
    VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
    const Comp = asChild ? Slot : 'span';

    return (
        <Comp
            data-slot="badge"
            className={cn(badgeVariants({ variant }), className)}
            {...props}
        />
    );
}

export { Badge, badgeVariants };
