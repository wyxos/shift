import { getTaskIdFromQuery, syncTaskQuery } from '@shared/tasks/history';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

describe('shared/tasks/history', () => {
    beforeEach(() => {
        window.history.replaceState({}, '', '/tasks');
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('reads a positive task id from the query string', () => {
        window.history.replaceState({}, '', '/tasks?task=42');
        expect(getTaskIdFromQuery()).toBe(42);
    });

    it('returns null for missing or invalid task ids', () => {
        window.history.replaceState({}, '', '/tasks');
        expect(getTaskIdFromQuery()).toBeNull();

        window.history.replaceState({}, '', '/tasks?task=abc');
        expect(getTaskIdFromQuery()).toBeNull();

        window.history.replaceState({}, '', '/tasks?task=0');
        expect(getTaskIdFromQuery()).toBeNull();
    });

    it('supports custom query param names', () => {
        window.history.replaceState({}, '', '/tasks?id=9');
        expect(getTaskIdFromQuery('id')).toBe(9);
        expect(getTaskIdFromQuery()).toBeNull();
    });

    it('pushes task query state when opening a task', () => {
        const pushStateSpy = vi.spyOn(window.history, 'pushState');
        syncTaskQuery(15, 'push');

        expect(window.location.search).toBe('?task=15');
        expect(pushStateSpy).toHaveBeenCalledOnce();
    });

    it('replaces query state when mode is replace', () => {
        const replaceStateSpy = vi.spyOn(window.history, 'replaceState');
        syncTaskQuery(23, 'replace');

        expect(window.location.search).toBe('?task=23');
        expect(replaceStateSpy).toHaveBeenCalledOnce();
    });

    it('removes the task query when task id is null', () => {
        window.history.replaceState({}, '', '/tasks?task=8&foo=bar');
        syncTaskQuery(null, 'push');
        expect(window.location.search).toBe('?foo=bar');
    });

    it('does nothing when the next URL equals the current URL', () => {
        window.history.replaceState({}, '', '/tasks?task=8');
        const pushStateSpy = vi.spyOn(window.history, 'pushState');

        syncTaskQuery(8, 'push');
        expect(pushStateSpy).not.toHaveBeenCalled();
    });

    it('updates custom query param names', () => {
        syncTaskQuery(3, 'push', 'id');
        expect(window.location.search).toBe('?id=3');
    });
});
