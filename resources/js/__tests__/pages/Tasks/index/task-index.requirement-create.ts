import Index from '@/pages/Tasks/Index.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { axiosGetMock, axiosPostMock, makeTasksPage, router, sonnerMocks } from './test-helpers';

describe('Tasks/Index.vue', () => {
    it('renders the requirements review surface with requirement language and create action', () => {
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
                surface: 'requirements',
                projects: [{ id: 42, name: 'Portal Review', can_create_task: true, environments: [] }],
                tasks: makeTasksPage([{ id: 1, title: 'Export renewal data', status: 'pending', priority: 'medium', phase: 'requirement' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        expect(wrapper.text()).toContain('Requirements');
        expect(wrapper.text()).toContain('Review submitted requirement items before they become active tasks.');
        expect(wrapper.text()).toContain('Showing 1 to 1 of 1 requirements');
        expect(wrapper.get('[data-testid="open-create-task"]').text()).toContain('Create Requirement');

        wrapper.unmount();
    });

    it('creates a requirement from the requirements review surface through the task store route', async () => {
        axiosPostMock.mockResolvedValueOnce({
            data: {
                ok: true,
                data: {
                    id: 9,
                    title: 'Portal requirement',
                    phase: 'requirement',
                    requirement_status: 'submitted',
                },
            },
        });

        const wrapper = mount(Index, {
            props: {
                surface: 'requirements',
                projects: [{ id: 42, name: 'Portal Review', can_create_task: true, environments: [] }],
                tasks: makeTasksPage([]),
                filters: { status: ['submitted'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.get('[data-testid="open-create-task"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Create Requirement');
        expect(wrapper.text()).toContain('Add a new requirement to your review queue.');

        await wrapper.get('[data-testid="create-task-title"]').setValue('Portal requirement');
        await wrapper.get('[data-testid="create-task-form"]').trigger('submit');
        await flushPromises();

        expect(axiosPostMock).toHaveBeenCalledWith(
            '/tasks.store',
            expect.objectContaining({
                title: 'Portal requirement',
                project_id: 42,
                phase: 'requirement',
            }),
        );
        expect(router.reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['tasks', 'filters', 'projects'],
            }),
        );
        expect(sonnerMocks.toastSuccessMock).toHaveBeenCalledWith('Requirement created', {
            description: 'Your requirement has been added to the review queue.',
        });

        wrapper.unmount();
    });
});
