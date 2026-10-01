import {
    getDefaultStatuses,
    getPriorityLabel,
    getPriorityOptions,
    getRequirementStatusLabel,
    getRequirementStatusOptions,
    getSortByOptions,
    getStatusLabel,
    getStatusOptions,
    getTaskStateIcon,
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
        expect(getDefaultStatuses(statuses)).toEqual(['pending', 'in-progress', 'awaiting-feedback', 'on-hold']);
    });

    it('exposes on hold as an open task status', () => {
        const statuses = getStatusOptions({ includeClosed: false });

        expect(statuses.map((option) => option.value)).toContain('on-hold');
        expect(getStatusLabel('on-hold', statuses)).toBe('On Hold');
        expect(getTaskStateIcon('on-hold')).not.toBe(getTaskStateIcon('pending'));
    });

    it('exposes requirement lifecycle options with neutral awaiting feedback wording', () => {
        const statuses = getRequirementStatusOptions();

        expect(statuses.map((option) => option.value)).toEqual([
            'submitted',
            'in-review',
            'awaiting-feedback',
            'ready-to-finalize',
            'parked',
            'declined',
        ]);
        expect(getRequirementStatusLabel('awaiting-feedback')).toBe('Awaiting Feedback');
        expect(getRequirementStatusLabel('ready-to-finalize')).toBe('Ready');
        expect(getRequirementStatusLabel('finalized')).toBe('Finalized');
        expect(getTaskStateIcon('declined')).not.toBe(getTaskStateIcon('finalized'));
        expect(getRequirementStatusLabel('unknown')).toBe('unknown');
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

    it('returns a fallback icon for unknown values', () => {
        expect(getTaskStateIcon('unknown')).toBeTruthy();
    });

    it('leaves task state selection styling to the shared ButtonGroup variants', () => {
        const statuses = getStatusOptions({ includeClosed: false });
        const priorities = getPriorityOptions();
        const requirements = getRequirementStatusOptions();

        expect([...statuses, ...priorities, ...requirements].every((option) => !('selectedClass' in option) && !('unselectedClass' in option))).toBe(
            true,
        );
    });

    it('exposes supported sort options', () => {
        expect(getSortByOptions().map((option) => option.value)).toEqual(['updated_at', 'created_at', 'priority']);
    });
});
