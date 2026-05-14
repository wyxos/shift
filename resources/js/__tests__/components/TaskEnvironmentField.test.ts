import TaskEnvironmentField from '@/components/tasks/TaskEnvironmentField.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('TaskEnvironmentField', () => {
    const projects = [
        {
            id: 42,
            name: 'Portal',
            environments: [
                { key: 'staging', label: 'Staging', url: 'https://portal.test' },
                { key: 'production', label: 'Production', url: 'https://portal.example.com' },
            ],
        },
    ];

    it('renders environment options as a button group', async () => {
        const wrapper = mount(TaskEnvironmentField, {
            props: {
                modelValue: null,
                projectId: 42,
                projects,
                testId: 'create-task-environment',
            },
        });

        expect(wrapper.find('select').exists()).toBe(false);
        expect(wrapper.get('[data-testid="create-task-environment-"]').text()).toBe('No environment');
        expect(wrapper.get('[data-testid="create-task-environment-staging"]').attributes('aria-checked')).toBe('false');

        await wrapper.get('[data-testid="create-task-environment-staging"]').trigger('click');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['staging']);
    });

    it('allows clearing the selected environment from the button group', async () => {
        const wrapper = mount(TaskEnvironmentField, {
            props: {
                modelValue: 'staging',
                projectId: 42,
                projects,
                testId: 'create-task-environment',
            },
        });

        await wrapper.get('[data-testid="create-task-environment-"]').trigger('click');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([null]);
    });
});
