import ProjectListTable from '@/components/admin/projects/ProjectListTable.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('ProjectListTable', () => {
    it('renders key project data and actions in the compact record layout', async () => {
        const wrapper = mount(ProjectListTable, {
            props: {
                projects: [
                    {
                        id: 1,
                        name: 'Portal Refresh',
                        client_name: 'Acme Client',
                        organisation_name: 'Acme Org',
                        isOwner: true,
                        mcp_enabled: true,
                    },
                ],
            },
        });

        const compactRow = wrapper.get('[data-testid="project-compact-row-1"]');

        expect(compactRow.text()).toContain('Portal Refresh');
        expect(compactRow.text()).toContain('Acme Org');
        expect(compactRow.text()).toContain('Owner');
        expect(compactRow.find('[data-slot="responsive-record-item-actions"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="project-compact-delete-1"]').exists()).toBe(true);

        await wrapper.get('[data-testid="project-compact-tasks-1"]').trigger('click');
        expect(wrapper.emitted('open-tasks')).toEqual([[expect.objectContaining({ id: 1 })]]);
    });
});
