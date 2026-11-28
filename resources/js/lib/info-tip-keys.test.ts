import { describe, expect, it } from 'vitest';
import { infoTipKeyAction } from './info-tip-keys';

describe('infoTipKeyAction', () => {
    it('opens on Enter and Space without toggling closed', () => {
        expect(infoTipKeyAction('Enter')).toBe('open');
        expect(infoTipKeyAction(' ')).toBe('open');
    });

    it('closes on Escape', () => {
        expect(infoTipKeyAction('Escape')).toBe('close');
    });

    it('ignores other keys', () => {
        expect(infoTipKeyAction('Tab')).toBe('ignore');
        expect(infoTipKeyAction('a')).toBe('ignore');
    });
});
