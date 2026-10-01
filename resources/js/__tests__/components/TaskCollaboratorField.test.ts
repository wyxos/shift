import TaskCollaboratorField from '@shared/components/TaskCollaboratorField.vue';
import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('axios');

const axiosGetMock = vi.mocked(axios.get);

describe('TaskCollaboratorField', () => {
    beforeEach(() => {
        axiosGetMock.mockReset();
        vi.useRealTimers();
        document.body.innerHTML = '';
        HTMLElement.prototype.scrollIntoView = vi.fn();
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    it('shows only the display name for selected collaborators and hides the redundant internal badge by default', () => {
        const wrapper = mount(TaskCollaboratorField, {
            props: {
                readOnly: true,
                modelValue: {
                    internal: [{ id: 1, name: 'QA Shift', email: 'qa.shift@example.com' }],
                    external: [{ id: 2, name: 'Codex QA', email: 'codex.qa@example.com' }],
                },
            },
        });

        expect(wrapper.find('[data-collaborator-badge-label-kind="internal"]').exists()).toBe(false);
        expect(wrapper.get('[data-collaborator-badge-value-kind="internal"]').text()).toBe('QA Shift');
        expect(wrapper.text()).not.toContain('qa.shift@example.com');
        expect(wrapper.get('[data-collaborator-badge-label-kind="external"]').text()).toBe('Guest');
        expect(wrapper.get('[data-collaborator-badge-value-kind="external"]').text()).toBe('Codex QA');
        expect(wrapper.text()).not.toContain('codex.qa@example.com');
    });

    it('supports consuming app badge labels and falls back to email when no name is available', () => {
        const wrapper = mount(TaskCollaboratorField, {
            props: {
                readOnly: true,
                internalBadgeLabel: 'SHIFT',
                externalBadgeLabel: null,
                modelValue: {
                    internal: [{ id: 1, name: 'QA Shift', email: 'qa.shift@example.com' }],
                    external: [{ id: 2, name: '', email: 'codex.qa.20260223@example.com' }],
                },
            },
        });

        expect(wrapper.get('[data-collaborator-badge-label-kind="internal"]').text()).toBe('SHIFT');
        expect(wrapper.find('[data-collaborator-badge-label-kind="external"]').exists()).toBe(false);
        expect(wrapper.get('[data-collaborator-badge-value-kind="external"]').text()).toBe('codex.qa.20260223@example.com');
    });

    function mountPicker(extraProps = {}) {
        return mount(TaskCollaboratorField, {
            attachTo: document.body,
            props: { lookupUrl: '/shift/api/task-collaborators', environment: 'staging', ...extraProps },
        });
    }

    async function searchFor(wrapper: ReturnType<typeof mountPicker>, term: string) {
        await wrapper.get('[data-testid="task-collaborators-search"]').setValue(term);
        await vi.advanceTimersByTimeAsync(250);
        await flushPromises();
    }

    it('waits for one typed character and shows both sources in an overlay without group filters', async () => {
        axiosGetMock.mockResolvedValue({
            data: {
                internal: [{ id: 1, name: 'Sam Owner', email: 'owner@example.com' }],
                external: [{ id: 'guest-1', name: 'Sam Guest', email: 'guest@example.com' }],
                internal_label: 'SHIFT team',
                external_label: 'Northwind users',
            },
        });
        const wrapper = mountPicker();
        await wrapper.get('[data-testid="task-collaborators-search"]').trigger('focus');
        expect(axiosGetMock).not.toHaveBeenCalled();
        expect(document.querySelector('[data-testid="task-collaborators-dropdown"]')).toBeNull();

        await searchFor(wrapper, 's');
        expect(axiosGetMock).toHaveBeenCalledWith('/shift/api/task-collaborators', {
            params: { search: 's', environment: 'staging' },
            signal: expect.any(AbortSignal),
        });
        expect(document.querySelector('[data-testid="internal-collaborator-option-1"]')).not.toBeNull();
        expect(document.querySelector('[data-testid="external-collaborator-option-guest-1"]')).not.toBeNull();
        expect(document.querySelector('[data-testid="task-collaborators-group-filter"]')).toBeNull();
        expect(document.querySelector('[data-testid="task-collaborators-dropdown"]')?.textContent).toContain('SHIFT team');
        expect(document.querySelector('[data-testid="task-collaborators-dropdown"]')?.textContent).toContain('Northwind users');
        expect(wrapper.find('[data-testid="task-collaborators-dropdown"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('shows ten combined matches and selects one with the keyboard', async () => {
        axiosGetMock.mockResolvedValue({
            data: {
                internal: Array.from({ length: 10 }, (_, id) => ({ id, name: `Sam ${id}`, email: `${id}@example.com` })),
                external: Array.from({ length: 10 }, (_, id) => ({ id: `guest-${id}`, name: `Sam guest ${id}`, email: `guest-${id}@example.com` })),
            },
        });
        const wrapper = mountPicker();
        await searchFor(wrapper, 'sam');
        expect(document.querySelectorAll('[data-testid*="-collaborator-option-"]')).toHaveLength(10);
        await wrapper.get('[data-testid="task-collaborators-search"]').trigger('keydown', { key: 'ArrowDown' });
        await wrapper.get('[data-testid="task-collaborators-search"]').trigger('keydown', { key: 'ArrowUp' });
        await wrapper.get('[data-testid="task-collaborators-search"]').trigger('keydown', { key: 'Enter' });
        await flushPromises();
        expect(wrapper.emitted('update:modelValue')?.[0]?.[0]).toMatchObject({ internal: [{ id: 0 }], external: [] });
        expect((wrapper.get('[data-testid="task-collaborators-search"]').element as HTMLInputElement).value).toBe('');
        expect(document.querySelector('[data-testid="task-collaborators-dropdown"]')).toBeNull();
        wrapper.unmount();
    });

    it('shows a check for already selected collaborators', async () => {
        const owner = { id: 1, name: 'Sam Owner', email: 'owner@example.com' };
        axiosGetMock.mockResolvedValue({ data: { internal: [owner], external: [] } });
        const wrapper = mountPicker({ modelValue: { internal: [owner], external: [] } });
        await searchFor(wrapper, 'sam');
        expect(document.querySelector('[data-testid="internal-collaborator-selected-1"]')).not.toBeNull();
        expect(document.querySelector('[data-testid="task-collaborators-dropdown"]')?.textContent).not.toContain('Selected');
        wrapper.unmount();
    });

    it('ignores a stale response after the search changes or is cleared', async () => {
        let resolveFirst: (response: unknown) => void = () => {};
        axiosGetMock.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    resolveFirst = resolve;
                }),
        );
        axiosGetMock.mockResolvedValueOnce({ data: { internal: [{ id: 2, name: 'Beatrice', email: 'b@example.com' }], external: [] } });
        const wrapper = mountPicker();
        await searchFor(wrapper, 'a');
        await searchFor(wrapper, 'b');
        resolveFirst({ data: { internal: [{ id: 1, name: 'Alice', email: 'a@example.com' }], external: [] } });
        await flushPromises();
        expect(document.querySelector('[data-testid="internal-collaborator-option-2"]')).not.toBeNull();
        expect(document.querySelector('[data-testid="internal-collaborator-option-1"]')).toBeNull();
        await searchFor(wrapper, '');
        expect(axiosGetMock).toHaveBeenCalledTimes(2);
        expect(document.querySelector('[data-testid="task-collaborators-dropdown"]')).toBeNull();
        wrapper.unmount();
    });

    it('keeps available results visible when the connected app cannot be reached', async () => {
        axiosGetMock.mockResolvedValue({
            data: {
                internal: [{ id: 1, name: 'Sam Owner', email: 'owner@example.com' }],
                external: [],
                external_available: false,
                external_error: 'The connected app could not be reached.',
            },
        });
        const wrapper = mountPicker();
        await searchFor(wrapper, 'sam');
        expect(document.querySelector('[data-testid="internal-collaborator-option-1"]')).not.toBeNull();
        expect(document.querySelector('[data-testid="task-collaborators-dropdown"]')?.textContent).toContain(
            'The connected app could not be reached.',
        );
        wrapper.unmount();
    });

    it('does not rely on Ziggy routes and cannot search or remove while disabled', async () => {
        delete (globalThis as any).route;
        const wrapper = mountPicker({ disabled: true });
        expect(wrapper.get('[data-testid="task-collaborators-search"]').attributes('disabled')).toBeDefined();
        await searchFor(wrapper, 'sam');
        expect(axiosGetMock).not.toHaveBeenCalled();
        wrapper.unmount();
    });
});
