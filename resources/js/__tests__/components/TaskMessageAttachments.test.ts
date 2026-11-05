import TaskMessageAttachments from '@shared/components/tasks/TaskMessageAttachments.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const image = { id: 1, url: '/attachments/1/download', original_filename: 'screenshot.png' };
describe('message attachments', () => {
    it('previews uploaded images separately and leaves ordinary files as download links', async () => {
        const wrapper = mount(TaskMessageAttachments, {
            props: { attachments: [image, { id: 2, url: '/attachments/2/download', original_filename: 'trace.txt' }] },
        });
        expect(wrapper.find('img').attributes('src')).toBe(image.url);
        expect(wrapper.find('a').attributes('href')).toBe('/attachments/2/download');
        await wrapper.find('button').trigger('click');
        expect(wrapper.emitted('imageClick')).toHaveLength(1);
    });
    it.each([true, false])('does not expose image requests or links when disabled=%s and URL is absent', (disabled) => {
        const wrapper = mount(TaskMessageAttachments, { props: { attachments: [{ original_filename: 'pending.png' }], disabled } });
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('a').exists()).toBe(false);
    });
    it('keeps pending/failed attachments inert and falls back to a link for broken previews', async () => {
        const wrapper = mount(TaskMessageAttachments, { props: { attachments: [image], disabled: true } });
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('a').exists()).toBe(false);
        await wrapper.setProps({ disabled: false });
        await wrapper.find('img').trigger('error');
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('a').attributes('href')).toBe(image.url);
    });
});
