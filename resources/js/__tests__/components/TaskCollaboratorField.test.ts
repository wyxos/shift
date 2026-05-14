import TaskCollaboratorField from '@shared/components/TaskCollaboratorField.vue';
import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('axios');

const axiosGetMock = vi.mocked(axios.get);

describe('TaskCollaboratorField', () => {
    beforeEach(() => {
        axiosGetMock.mockReset();
        vi.useRealTimers();
        (globalThis as any).route = vi.fn((name: string, params?: Record<string, unknown>) => `/${name}/${params?.project ?? ''}`);
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

    it('uses an inline searchable collaborator picker with Team and Project users filters', async () => {
        axiosGetMock.mockResolvedValue({
            data: {
                internal: [{ id: 1, name: 'Shift Owner', email: 'owner@example.com' }],
                external: [{ id: 'guest-1', name: 'Client Guest', email: 'guest@example.com' }],
                internal_available: true,
                external_available: true,
            },
        });

        const wrapper = mount(TaskCollaboratorField, {
            props: {
                projectId: 42,
                environment: 'staging',
            },
        });

        await flushPromises();

        expect(wrapper.find('[data-testid="task-collaborators-trigger"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="task-collaborators-dropdown"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="task-collaborators-group-internal"]').text()).toContain('Team');
        expect(wrapper.get('[data-testid="task-collaborators-group-external"]').text()).toContain('Project users');
        expect(wrapper.find('[data-testid="internal-collaborator-option-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="external-collaborator-option-guest-1"]').exists()).toBe(false);

        await wrapper.get('[data-testid="task-collaborators-group-external"]').trigger('click');

        expect(wrapper.find('[data-testid="internal-collaborator-option-1"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="external-collaborator-option-guest-1"]').exists()).toBe(true);
    });

    it('shows a check icon instead of visible Selected text for selected collaborators', async () => {
        axiosGetMock.mockResolvedValue({
            data: {
                internal: [{ id: 1, name: 'Shift Owner', email: 'owner@example.com' }],
                external: [],
                internal_available: true,
                external_available: true,
            },
        });

        const wrapper = mount(TaskCollaboratorField, {
            props: {
                projectId: 42,
                modelValue: {
                    internal: [{ id: 1, name: 'Shift Owner', email: 'owner@example.com' }],
                    external: [],
                },
            },
        });

        await flushPromises();

        expect(wrapper.get('[data-testid="internal-collaborator-selected-1"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="task-collaborators-dropdown"]').text()).not.toContain('Selected');
    });

    it('sends search text through the collaborator lookup', async () => {
        vi.useFakeTimers();
        axiosGetMock.mockResolvedValue({
            data: {
                internal: [],
                external: [],
                internal_available: true,
                external_available: true,
            },
        });

        const wrapper = mount(TaskCollaboratorField, {
            props: {
                projectId: 42,
                environment: 'staging',
            },
        });

        await flushPromises();
        await wrapper.get('[data-testid="task-collaborators-search"]').setValue('guest');
        vi.advanceTimersByTime(250);
        await flushPromises();

        expect(axiosGetMock).toHaveBeenLastCalledWith('/tasks.v2.collaborators/42', {
            params: {
                search: 'guest',
                environment: 'staging',
            },
        });
    });

    it('uses collaborator lookup labels and descriptions when the API provides them', async () => {
        axiosGetMock.mockResolvedValue({
            data: {
                internal: [{ id: 1, name: 'Shift Owner', email: 'owner@example.com' }],
                external: [{ id: 'guest-1', name: 'Client Guest', email: 'guest@example.com' }],
                internal_available: true,
                external_available: true,
                internal_label: 'Northwind Organisation',
                internal_description: 'Users with access in SHIFT.',
                external_label: 'Team',
                external_description: 'Users with access from this portal.',
            },
        });

        const wrapper = mount(TaskCollaboratorField, {
            props: {
                lookupUrl: '/shift/api/task-collaborators',
                internalLabel: 'Fallback',
                externalLabel: 'Project Users',
            },
        });

        await flushPromises();

        expect(wrapper.get('[data-testid="task-collaborators-group-internal"]').text()).toContain('Northwind Organisation');
        expect(wrapper.get('[data-testid="task-collaborators-group-internal"]').text()).toContain('Users with access in SHIFT.');
        expect(wrapper.get('[data-testid="task-collaborators-group-external"]').text()).toContain('Team');
        expect(wrapper.get('[data-testid="task-collaborators-group-external"]').text()).toContain('Users with access from this portal.');
        expect(wrapper.get('[data-testid="task-collaborators-dropdown"]').text()).not.toContain('Project Users');
    });
});
