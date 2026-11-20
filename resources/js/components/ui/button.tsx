import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

const buttonVariants = cva(
    "inline-flex items-center justify-center gap-1.5 rounded-md text-sm font-medium whitespace-nowrap transition-colors duration-150 motion-reduce:transition-none disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 outline-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background aria-invalid:ring-danger/20 aria-invalid:border-danger",
    {
        variants: {
            variant: {
                default:
                    'bg-primary text-primary-foreground shadow-xs hover:bg-primary-hover',
                destructive:
                    'bg-danger text-white shadow-xs hover:bg-[#B91C1C] focus-visible:ring-danger/30',
                outline:
                    'border border-border bg-white text-foreground shadow-xs hover:bg-surface dark:bg-card',
                secondary:
                    'border border-border bg-white text-foreground shadow-xs hover:bg-surface dark:bg-card',
                ghost: 'text-[#334155] hover:bg-muted hover:text-foreground dark:text-muted-foreground',
                link: 'text-primary underline-offset-4 hover:underline',
                dark: 'bg-foreground text-white hover:bg-[#1E293B]',
            },
            size: {
                default: 'h-9 px-3.5',
                sm: 'h-8 px-3 text-xs',
                lg: 'h-10 px-4',
                xl: 'h-11 px-5 text-[15px]',
                icon: 'size-9',
                'icon-sm': 'size-8',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

function Button({
    className,
    variant,
    size,
    asChild = false,
    ...props
}: React.ComponentProps<'button'> &
    VariantProps<typeof buttonVariants> & {
        asChild?: boolean;
    }) {
    const Comp = asChild ? Slot : 'button';

    return (
        <Comp
            data-slot="button"
            className={cn(buttonVariants({ variant, size, className }))}
            {...props}
        />
    );
}

export { Button, buttonVariants };
