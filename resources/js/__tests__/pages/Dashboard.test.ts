import Dashboard from '@/pages/Dashboard.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

// Mock the AppLayout component
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: {
        props: ['breadcrumbs'],
        render() {
            return h('div', { class: 'app-layout' }, this.$slots.default?.());
        },
    },
}));

// Mock Inertia components
vi.mock('@inertiajs/vue3', () => ({
    Head: {
        render: () => {},
    },
    Link: {
        props: ['href'],
        render() {
            return h('a', { href: this.href || '#' }, this.$slots.default?.());
        },
    },
}));

describe('Dashboard.vue', () => {
    const mockMetrics = {
        total: 100,
        pending: 25,
        in_progress: 50,
        completed: 25,
    };

    it('renders dashboard metrics correctly', () => {
        const wrapper = mount(Dashboard, {
            props: {
                metrics: mockMetrics,
            },
        });

        // Check if metrics are displayed correctly
        expect(wrapper.text()).toContain('25'); // Pending
        expect(wrapper.text()).toContain('50'); // In Progress
        expect(wrapper.text()).toContain('25'); // Completed
        expect(wrapper.text()).toContain('100'); // Total

        // Check if labels are displayed
        expect(wrapper.text()).toContain('Pending');
        expect(wrapper.text()).toContain('In Progress');
        expect(wrapper.text()).toContain('Completed');
        expect(wrapper.text()).toContain('Total Tasks');
    });
});
