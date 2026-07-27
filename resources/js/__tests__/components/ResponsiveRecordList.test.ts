import { ResponsiveRecordItem, ResponsiveRecordList } from '@/components/ui/record-list';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('ResponsiveRecordList', () => {
    it('provides separate desktop and compact surfaces at the large breakpoint', () => {
        const wrapper = mount(ResponsiveRecordList, {
            props: {
                empty: false,
                emptyLabel: 'No records found.',
                label: 'Records',
            },
            slots: {
                desktop: '<table data-testid="desktop-records"></table>',
                compact: '<div data-testid="compact-record">Compact record</div>',
            },
        });

        expect(wrapper.get('[data-slot="responsive-record-list-desktop"]').classes()).toEqual(expect.arrayContaining(['hidden', 'lg:block']));
        expect(wrapper.get('[data-slot="responsive-record-list-compact"]').classes()).toContain('lg:hidden');
        expect(wrapper.get('[role="list"]').attributes('aria-label')).toBe('Records');
    });

    it('keeps compact actions in a predictable bottom row', () => {
        const wrapper = mount(ResponsiveRecordItem, {
            slots: {
                default: '<h2>Record title</h2>',
                actions: '<button type="button">Open</button>',
            },
        });

        const content = wrapper.get('[data-slot="responsive-record-item-content"]');
        const actions = wrapper.get('[data-slot="responsive-record-item-actions"]');

        expect(wrapper.attributes('role')).toBe('listitem');
        expect(content.text()).toContain('Record title');
        expect(actions.text()).toContain('Open');
        expect(content.element.nextElementSibling).toBe(actions.element);
    });

    it('renders a compact status message for empty lists', () => {
        const wrapper = mount(ResponsiveRecordList, {
            props: {
                empty: true,
                emptyLabel: 'No records found.',
                label: 'Records',
            },
        });

        expect(wrapper.get('[role="status"]').text()).toBe('No records found.');
        expect(wrapper.find('[role="list"]').exists()).toBe(false);
    });
});
