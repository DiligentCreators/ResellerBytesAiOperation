/**
 * Decide how a keypress should affect an InfoTip while the trigger is focused.
 * Enter/Space always open (never toggle closed). Escape closes.
 */
export function infoTipKeyAction(key: string): 'open' | 'close' | 'ignore' {
    if (key === 'Escape') {
        return 'close';
    }

    if (key === 'Enter' || key === ' ') {
        return 'open';
    }

    return 'ignore';
}
