import TaskCommentContent from '@shared/components/tasks/TaskCommentContent.vue';
import TaskMessageAttachments from '@shared/components/tasks/TaskMessageAttachments.vue';
import { useTaskThreadRichInteraction } from '@shared/tasks/useTaskThreadRichInteraction';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h, ref } from 'vue';

function fixture() {
    const Harness = defineComponent({
        setup() {
            const interaction = useTaskThreadRichInteraction({
                cancelThreadEdit: () => {},
                commentsScrollRef: ref(null),
                editOpen: ref(true),
                threadEditingId: ref(null),
            });
            return () =>
                h('div', [
                    h(TaskCommentContent, { content: '<p><img src="/body.png" alt="Pasted image"></p>', onClick: interaction.onRichContentClick }),
                    h(TaskMessageAttachments, {
                        attachments: [{ id: 1, original_filename: 'upload.png', url: '/attachments/1/download' }],
                        onImageClick: interaction.onRichContentClick,
                    }),
                    h('output', { 'data-testid': 'viewer-source' }, interaction.lightboxOpen.value ? interaction.lightboxSrc.value : ''),
                ]);
        },
    });
    return mount(Harness);
}

describe('task image viewer integration', () => {
    it('opens an uploaded image when the attachment button itself is activated', async () => {
        const wrapper = fixture();
        await wrapper.find('[data-message-image]').trigger('click');
        expect(wrapper.find('output').text()).toMatch(/\/attachments\/1\/download$/);
        wrapper.unmount();
    });
    it.each(['Enter', ' '])('opens a pasted image with the %s key', async (key) => {
        const wrapper = fixture();
        const image = wrapper.find('.shift-rich img');
        expect(image.attributes('tabindex')).toBe('0');
        expect(image.attributes('role')).toBe('button');
        await image.trigger('keydown', { key });
        expect(wrapper.find('output').text()).toMatch(/\/body.png$/);
        wrapper.unmount();
    });
});
