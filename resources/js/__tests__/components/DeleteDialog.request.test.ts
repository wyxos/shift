import DeleteDialog from '@/components/DeleteDialog.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/components/ui/dialog', () => ({
    Dialog: {
        props: ['open'],
        template: '<div v-if="open"><slot /></div>',
    },
    DialogContent: {
        template: '<div><slot /></div>',
    },
    DialogDescription: {
        template: '<p><slot /></p>',
    },
    DialogFooter: {
        template: '<div><slot /></div>',
    },
    DialogHeader: {
        template: '<div><slot /></div>',
    },
    DialogTitle: {
        template: '<h2><slot /></h2>',
    },
}));

vi.mock('@/components/ui/button', () => ({
    Button: {
        props: ['disabled', 'type', 'variant'],
        template: '<button v-bind="$attrs" :disabled="disabled" :type="type || `button`"><slot /></button>',
    },
}));

describe('DeleteDialog request state', () => {
    it('shows a busy destructive action and disables cancel while a request is pending', () => {
        const wrapper = mount(DeleteDialog, {
            props: {
                isOpen: true,
                loading: true,
                loadingLabel: 'Deleting...',
            },
            slots: {
                title: 'Delete record',
                description: 'This cannot be undone.',
                confirm: 'Delete',
            },
        });

        const buttons = wrapper.findAll('button');
        expect(buttons[0].attributes('disabled')).toBeDefined();
        expect(buttons[1].attributes('disabled')).toBeDefined();
        expect(buttons[1].attributes('aria-busy')).toBe('true');
        expect(buttons[1].text()).toContain('Deleting...');
    });

    it('renders request errors inside the dialog', () => {
        const wrapper = mount(DeleteDialog, {
            props: {
                isOpen: true,
                error: 'Unable to delete this record.',
            },
        });

        expect(wrapper.get('[data-testid="delete-dialog-error"]').text()).toContain('Unable to delete this record.');
    });
});
