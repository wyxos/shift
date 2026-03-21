import TaskCollaboratorField from '@shared/components/TaskCollaboratorField.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('TaskCollaboratorField', () => {
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
        expect(wrapper.get('[data-collaborator-badge-label-kind="external"]').text()).toBe('External');
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
});
