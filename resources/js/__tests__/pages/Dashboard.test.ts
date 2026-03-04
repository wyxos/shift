import Dashboard from '@/pages/Dashboard.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

function passthroughComponent() {
    return {
        render() {
            return h('div', {}, this.$slots.default?.());
        },
    };
}

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

vi.mock('@/components/ui/chart', () => ({
    ChartContainer: passthroughComponent(),
    ChartTooltip: passthroughComponent(),
    ChartCrosshair: passthroughComponent(),
    ChartTooltipContent: passthroughComponent(),
    componentToString: vi.fn(() => ''),
}));

vi.mock('@unovis/vue', () => ({
    VisAxis: passthroughComponent(),
    VisDonut: passthroughComponent(),
    VisGroupedBar: passthroughComponent(),
    VisSingleContainer: passthroughComponent(),
    VisXYContainer: passthroughComponent(),
}));

vi.mock('@unovis/ts', () => ({
    Donut: {
        selectors: {
            segment: 'segment',
        },
    },
}));

describe('Dashboard.vue', () => {
    const mockMetrics = {
        total: 100,
        pending: 25,
        in_progress: 50,
        completed: 25,
        open: 80,
        awaiting_feedback: 5,
        high_priority_open: 12,
        completion_rate: 25,
    };

    const mockCharts = {
        status: [
            { key: 'pending', label: 'Pending', count: 25 },
            { key: 'in-progress', label: 'In Progress', count: 50 },
            { key: 'completed', label: 'Completed', count: 25 },
        ],
        priority: [
            { key: 'high', label: 'High', count: 30 },
            { key: 'medium', label: 'Medium', count: 40 },
            { key: 'low', label: 'Low', count: 30 },
        ],
        throughput: [
            { week_start: '2026-01-05', label: 'Jan 5', created: 8, completed: 4 },
            { week_start: '2026-01-12', label: 'Jan 12', created: 10, completed: 9 },
        ],
        environments: [
            { key: 'production', label: 'Production', count: 70 },
            { key: 'staging', label: 'Staging', count: 30 },
        ],
        projects: [
            { project: 'Platform', count: 40 },
            { project: 'Client API', count: 25 },
        ],
    };

    it('renders dashboard metrics correctly', () => {
        const wrapper = mount(Dashboard, {
            props: {
                metrics: mockMetrics,
                charts: mockCharts,
            },
        });

        expect(wrapper.text()).toContain('Task Intelligence');
        expect(wrapper.text()).toContain('Total Tasks');
        expect(wrapper.text()).toContain('Open Work');
        expect(wrapper.text()).toContain('Completion Rate');
        expect(wrapper.text()).toContain('High Priority Open');
        expect(wrapper.text()).toContain('100');
        expect(wrapper.text()).toContain('80');
        expect(wrapper.text()).toContain('25.0%');
        expect(wrapper.text()).toContain('12');
        expect(wrapper.text()).toContain('Weekly Throughput');
        expect(wrapper.text()).toContain('Status Distribution');
        expect(wrapper.text()).toContain('Priority Mix');
        expect(wrapper.text()).toContain('Project Load');
        expect(wrapper.text()).toContain('Environment Exposure');
    });
});
