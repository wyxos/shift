import { formatListDateTime } from '@shared/tasks/date-time';
import { describe, expect, it } from 'vitest';

describe('formatListDateTime', () => {
    const now = new Date(2026, 8, 2, 15, 30);

    it('uses relative labels for recent timestamps', () => {
        expect(formatListDateTime(new Date(2026, 8, 2, 15, 29), now)).toMatchObject({ label: '1 minute ago', time: '15:29' });
        expect(formatListDateTime(new Date(2026, 8, 2, 15, 18), now)).toMatchObject({ label: '12 minutes ago', time: '15:18' });
        expect(formatListDateTime(new Date(2026, 8, 2, 13, 0), now)).toMatchObject({ label: 'Today', time: '13:00' });
    });

    it('uses yesterday and dated labels for older timestamps', () => {
        expect(formatListDateTime(new Date(2026, 8, 1, 9, 5), now)).toMatchObject({ label: 'Yesterday', time: '09:05' });
        expect(formatListDateTime(new Date(2026, 7, 31, 8, 45), now)).toMatchObject({ label: '31 Aug', time: '08:45' });
        expect(formatListDateTime(new Date(2025, 11, 31, 8, 45), now)).toMatchObject({ label: '31 Dec 2025', time: '08:45' });
    });

    it('handles missing and invalid timestamps', () => {
        expect(formatListDateTime(null, now)).toEqual({ dateTime: null, label: 'Unknown', time: '' });
        expect(formatListDateTime('not-a-date', now)).toEqual({ dateTime: null, label: 'Unknown', time: '' });
    });
});
