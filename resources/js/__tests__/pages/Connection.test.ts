import Connection from '@/pages/Connection.vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({ get: vi.fn(), patch: vi.fn(), reload: vi.fn() }));

vi.mock('@/layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: inertia,
}));
vi.mock('@/components/ui/select', () => ({
    Select: {
        props: ['modelValue', 'options'],
        emits: ['update:modelValue'],
        template:
            '<button type="button" data-testid="project-select" @click="$emit(\'update:modelValue\', options[1]?.value)">{{ modelValue }}</button>',
    },
}));

const baseProps = {
    portalUrl: 'https://shift.test',
    projects: [
        { id: 1, name: 'Alpha' },
        { id: 2, name: 'Beta' },
    ],
    selectedProjectId: 1,
    environments: [
        {
            id: 12,
            environment: 'local',
            label: 'Local',
            url: 'https://app.test',
            feedback_enabled: false,
            feedback_report: null,
            error_report: null,
        },
    ],
};

describe('Connection.vue', () => {
    beforeEach(() => {
        inertia.get.mockReset();
        inertia.patch.mockReset();
        inertia.reload.mockReset();
    });

    it('separates registration from received reports and enables Feedback for one environment', async () => {
        const wrapper = mount(Connection, { props: baseProps });

        expect(wrapper.text()).toContain('Environment registered');
        expect(wrapper.text()).toContain('SHIFT_URL=https://shift.test');
        expect(wrapper.text()).toContain('SHIFT_WIDGET_ENABLED=true');
        expect(wrapper.text()).toContain('SHIFT_ERROR_REPORTING_ENABLED=true');
        expect(wrapper.text()).toContain('php artisan config:clear');
        expect(wrapper.text()).toContain('php artisan install:shift');
        expect(wrapper.text()).not.toContain('When asked for the SHIFT portal');
        expect(wrapper.text()).toContain('No report received');
        expect(wrapper.text()).toContain('No error received');
        expect(wrapper.text()).toContain('php artisan shift:test --error');
        expect(wrapper.text()).not.toContain('Open received Feedback report');
        expect(wrapper.get('a[href="https://app.test"]').attributes('target')).toBe('_blank');
        expect(wrapper.get('[data-testid="connection-environment-12"]').text()).toContain('Feedback is disabled for this environment');

        await wrapper
            .findAll('button')
            .find((button) => button.text().includes('Enable Feedback'))!
            .trigger('click');
        expect(inertia.patch).toHaveBeenCalledWith(
            '/projects/1/connection/environments/12',
            { feedback_enabled: true },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('links only observed reports and refreshes on request', async () => {
        const wrapper = mount(Connection, {
            props: {
                ...baseProps,
                environments: [
                    {
                        ...baseProps.environments[0],
                        feedback_enabled: true,
                        feedback_report: { id: 100, title: 'Feedback test', url: '/tasks?task=100' },
                        error_report: { id: 101, title: 'Error test', url: '/tasks?task=101' },
                    },
                ],
            },
        });

        expect(wrapper.get('a[href="/tasks?task=100"]').text()).toContain('Feedback');
        expect(wrapper.get('a[href="/tasks?task=101"]').text()).toContain('error');

        await wrapper.get('[data-testid="project-select"]').trigger('click');
        expect(inertia.get).toHaveBeenCalledWith('/connect', { project_id: 2 }, { preserveState: false });

        await wrapper
            .findAll('button')
            .find((button) => button.text().includes('Refresh connection status'))!
            .trigger('click');
        expect(inertia.reload).toHaveBeenCalledOnce();
    });
});
