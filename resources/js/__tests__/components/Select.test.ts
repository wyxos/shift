import { Select } from '@/components/ui/select';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';

describe('Select', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('filters searchable options and emits the selected value', async () => {
        const wrapper = mount(Select, {
            attachTo: document.body,
            props: {
                modelValue: null,
                options: [
                    { value: 1, label: 'Atlas Commerce' },
                    { value: 2, label: 'Cedar Labs' },
                    { value: 3, label: 'Northwind Studio' },
                ],
                placeholder: 'Select project',
                searchable: true,
                testId: 'project-select',
            },
        });

        await wrapper.get('[data-testid="project-select"]').trigger('click');
        await nextTick();

        const search = document.body.querySelector('[data-testid="project-select-search"]') as HTMLInputElement;
        expect(search).not.toBeNull();
        expect(document.body.textContent).toContain('Atlas Commerce');
        expect(document.body.textContent).toContain('Cedar Labs');

        search.value = 'cedar';
        search.dispatchEvent(new Event('input'));
        await nextTick();

        expect(document.body.textContent).not.toContain('Atlas Commerce');
        expect(document.body.textContent).toContain('Cedar Labs');

        const cedarOption = document.body.querySelector('[data-testid="project-select-option-2"]') as HTMLButtonElement;
        cedarOption.click();
        await nextTick();

        expect(wrapper.emitted('update:modelValue')).toEqual([[2]]);

        wrapper.unmount();
    });

    it('renders finite dropdowns without a search field', async () => {
        const wrapper = mount(Select, {
            attachTo: document.body,
            props: {
                modelValue: 'pending',
                options: [
                    { value: 'pending', label: 'Pending' },
                    { value: 'completed', label: 'Completed' },
                ],
                testId: 'status-select',
            },
        });

        await wrapper.get('[data-testid="status-select"]').trigger('click');
        await nextTick();

        expect(document.body.querySelector('[data-testid="status-select-search"]')).toBeNull();
        expect(document.body.textContent).toContain('Completed');

        wrapper.unmount();
    });
});
