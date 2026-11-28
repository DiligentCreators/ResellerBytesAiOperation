import { Info } from 'lucide-react';
import { useState, type KeyboardEvent } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { infoTipKeyAction } from '@/lib/info-tip-keys';
import { cn } from '@/lib/utils';

type InfoTipProps = {
    label: string;
    text: string;
    className?: string;
    side?: 'top' | 'right' | 'bottom' | 'left';
    'data-testid'?: string;
};

/**
 * Keyboard-focusable info tip for plain-English help next to a label.
 * Opens on hover, Tab focus, Enter/Space (open-only, not toggle), and tap.
 * Escape closes.
 */
export function InfoTip({
    label,
    text,
    className,
    side = 'top',
    'data-testid': dataTestId,
}: InfoTipProps) {
    const [open, setOpen] = useState(false);

    const handleKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
        const action = infoTipKeyAction(event.key);

        if (action === 'ignore') {
            return;
        }

        event.preventDefault();

        if (action === 'open') {
            setOpen(true);
        } else {
            setOpen(false);
        }
    };

    return (
        <Tooltip open={open} onOpenChange={setOpen}>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'inline-flex size-5 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:outline-none',
                        className,
                    )}
                    aria-label={`More info about ${label}`}
                    aria-expanded={open}
                    data-info-tip={label}
                    data-testid={dataTestId}
                    onFocus={() => setOpen(true)}
                    onKeyDown={handleKeyDown}
                    onClick={() => setOpen(true)}
                >
                    <Info className="size-3.5" aria-hidden />
                </button>
            </TooltipTrigger>
            <TooltipContent
                side={side}
                role="tooltip"
                className="max-w-xs text-left"
            >
                {text}
            </TooltipContent>
        </Tooltip>
    );
}
