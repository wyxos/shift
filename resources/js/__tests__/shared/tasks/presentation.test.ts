import {
    getDefaultStatuses,
    getPriorityBadgeClass,
    getPriorityLabel,
    getPriorityOptions,
    getSortByOptions,
    getStatusBadgeClass,
    getStatusLabel,
    getStatusOptions,
    normalizeStringList,
} from '@shared/tasks/presentation';
import { describe, expect, it } from 'vitest';

describe('shared/tasks/presentation', () => {
    it('returns status options with optional closed status', () => {
        const withClosed = getStatusOptions({ includeClosed: true });
        const withoutClosed = getStatusOptions({ includeClosed: false });

        expect(withClosed.some((option) => option.value === 'closed')).toBe(true);
        expect(withoutClosed.some((option) => option.value === 'closed')).toBe(false);
    });

    it('computes default statuses by excluding completed/closed', () => {
        const statuses = getStatusOptions({ includeClosed: true });
        expect(getDefaultStatuses(statuses)).toEqual(['pending', 'in-progress', 'awaiting-feedback']);
    });

    it('normalizes string list filters', () => {
        expect(normalizeStringList([' pending ', '', 'high'])).toEqual([' pending ', 'high']);
        expect(normalizeStringList('staging')).toEqual(['staging']);
        expect(normalizeStringList(null)).toEqual([]);
    });

    it('resolves labels from options with fallback to raw value', () => {
        const statusOptions = getStatusOptions({ includeClosed: false });
        const priorityOptions = getPriorityOptions();

        expect(getStatusLabel('pending', statusOptions)).toBe('Pending');
        expect(getStatusLabel('unknown', statusOptions)).toBe('unknown');
        expect(getPriorityLabel('high', priorityOptions)).toBe('High');
        expect(getPriorityLabel('unknown', priorityOptions)).toBe('unknown');
    });

    it('returns fallback badge classes for unknown values', () => {
        expect(getStatusBadgeClass('unknown')).toContain('border-zinc-300');
        expect(getPriorityBadgeClass('unknown')).toContain('border-zinc-300');
    });

    it('includes dark mode state classes for status and priority options', () => {
        const statuses = getStatusOptions({ includeClosed: false });
        const priorities = getPriorityOptions();

        expect(statuses.every((option) => option.selectedClass.includes('dark:bg-') && option.unselectedClass.includes('dark:bg-'))).toBe(true);
        expect(priorities.every((option) => option.selectedClass.includes('dark:bg-') && option.unselectedClass.includes('dark:bg-'))).toBe(true);
    });

    it('exposes supported sort options', () => {
        expect(getSortByOptions().map((option) => option.value)).toEqual(['updated_at', 'created_at', 'priority']);
    });
});
